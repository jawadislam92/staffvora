<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

class SlackReportService
{
    private const MAX_TABLE_DATA_ROWS = 98;

    public function __construct(private SlackReportDestination $destination) {}

    /**
     * This is the service behind /report/slack AND the scheduled weekly report
     * (tenant:run reports:send-weekly-slack), so it was the automated half of
     * the shared-webhook leak: every tenant's work-hours table posted, unattended,
     * to one platform-wide channel. It now resolves the workspace's own Slack —
     * see SlackReportDestination.
     */
    public function configured(): bool
    {
        return $this->destination->configured();
    }

    public function sendRange(CarbonInterface $start, CarbonInterface $end, array $filters = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException($this->destination->unconfiguredMessage());
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $query = WorkHour::query()->whereBetween('date', [$startDate, $endDate]);

        if (empty($filters['userIds'])) {
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('include_in_slack_reports', true));
        }

        $this->applyFilters($query, $filters);

        // workCategory is eager-loaded (one extra query for the whole digest,
        // not one per entry) because the work-type column is now rendered from
        // the workspace's own category name — the single vocabulary source.
        $entries = $query
            ->with(['client:id,name', 'workCategory:id,name'])
            ->orderBy('user_id')
            ->orderBy('date')
            ->get(['id', 'user_id', 'client_id', 'work_category_id', 'is_billable', 'work_type', 'tracker', 'hours']);

        $users = User::query()
            ->when(
                ! empty($filters['userIds']),
                fn ($userQuery) => $userQuery->whereIn('id', $filters['userIds']),
                fn ($userQuery) => $userQuery
                    ->where('include_in_slack_reports', true)
                    ->whereIn('id', $entries->pluck('user_id')->unique())
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        $includeFields = array_values(array_intersect(
            $filters['includeFields'] ?? ['hours'],
            ['client', 'work_type', 'billable', 'tracker', 'hours', 'user_total']
        ));

        if ($includeFields === []) {
            $includeFields = ['hours'];
        }

        $columns = array_merge(['user'], $includeFields);
        $reportRows = $this->buildReportRows($users, $entries, $includeFields);
        $visibleRows = $reportRows->take(self::MAX_TABLE_DATA_ROWS);
        $omittedRows = max(0, $reportRows->count() - $visibleRows->count());
        $teamTotal = (float) $entries->sum('hours');
        $period = $start->format('M j, Y').' - '.$end->format('M j, Y');
        $tableRows = collect([
            collect($columns)
                ->map(fn (string $column) => $this->tableBoldCell($this->fieldLabel($column)))
                ->all(),
        ]);

        $visibleRows->each(function (array $row) use ($tableRows, $columns) {
            $tableRows->push(
                collect($columns)
                    ->map(fn (string $column) => $this->tableTextCell($row[$column] ?? '-'))
                    ->all()
            );
        });

        if (in_array('hours', $includeFields, true)) {
            $tableRows->push(
                collect($columns)
                    ->map(function (string $column) use ($teamTotal) {
                        if ($column === 'user') {
                            return $this->tableBoldCell('Total');
                        }

                        if ($column === 'hours') {
                            return $this->tableBoldCell($this->formatHours($teamTotal));
                        }

                        return $this->tableTextCell('-');
                    })
                    ->all()
            );
        }

        $contextParts = [
            sprintf('*Users:* %d', $users->count()),
            sprintf('*Entries:* %d', $entries->count()),
        ];

        if ($omittedRows > 0) {
            $contextParts[] = sprintf('*%d additional table rows omitted*', $omittedRows);
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Work Hours Report',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Period:* {$period}",
                ],
            ],
            [
                'type' => 'table',
                'column_settings' => collect($columns)
                    ->map(fn (string $column) => [
                        'align' => in_array($column, ['hours', 'user_total'], true) ? 'right' : 'left',
                        'is_wrapped' => ! in_array($column, ['hours', 'user_total'], true),
                    ])
                    ->all(),
                'rows' => $tableRows->all(),
            ],
            [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => implode(' | ', $contextParts),
                ]],
            ],
        ];

        $fallbackText = sprintf(
            'Work Hours Report | %s | %d users | %d entries%s',
            $period,
            $users->count(),
            $entries->count(),
            in_array('hours', $includeFields, true)
                ? ' | Total hours: '.$this->formatHours($teamTotal)
                : ''
        );

        $this->destination->send([
            'text' => $fallbackText,
            'blocks' => $blocks,
        ]);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'user_count' => $users->count(),
            'total_hours' => round($teamTotal, 2),
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['userIds'])) {
            $query->whereIn('user_id', $filters['userIds']);
        }

        if (! empty($filters['workTypes'])) {
            $query->whereIn('work_type', $filters['workTypes']);
        }

        if (! empty($filters['trackers'])) {
            $query->whereIn('tracker', $filters['trackers']);
        }

        if (! empty($filters['clients'])) {
            $query->whereHas('client', fn ($clientQuery) => $clientQuery->whereIn('name', $filters['clients']));
        }

        if (! empty($filters['designations'])) {
            $query->whereHas('user', fn ($userQuery) => $userQuery->whereIn('designation', $filters['designations']));
        }
    }

    private function buildReportRows(Collection $users, Collection $entries, array $includeFields): Collection
    {
        $groupFields = array_values(array_diff($includeFields, ['hours', 'user_total']));

        return $users->flatMap(function (User $user) use ($entries, $groupFields, $includeFields) {
            $userEntries = $entries->where('user_id', $user->id)->values();
            $userTotal = $this->formatHours((float) $userEntries->sum('hours'));

            if ($userEntries->isEmpty()) {
                return [[
                    'user' => $user->name,
                    ...collect($includeFields)
                        ->mapWithKeys(fn (string $field) => [
                            $field => in_array($field, ['hours', 'user_total'], true) ? '0' : '-',
                        ])
                        ->all(),
                ]];
            }

            if ($groupFields === []) {
                $row = [
                    'user' => $user->name,
                ];

                if (in_array('hours', $includeFields, true)) {
                    $row['hours'] = $userTotal;
                }

                if (in_array('user_total', $includeFields, true)) {
                    $row['user_total'] = $userTotal;
                }

                return [$row];
            }

            return $userEntries
                ->groupBy(fn (WorkHour $entry) => json_encode(
                    collect($groupFields)->mapWithKeys(
                        fn (string $field) => [$field => $this->fieldValue($entry, $field)]
                    )->all()
                ))
                ->sortKeys()
                ->map(function (Collection $group) use ($user, $groupFields, $includeFields, $userTotal) {
                    $entry = $group->first();
                    $row = ['user' => $user->name];

                    foreach ($groupFields as $field) {
                        $row[$field] = $this->fieldValue($entry, $field);
                    }

                    if (in_array('hours', $includeFields, true)) {
                        $row['hours'] = $this->formatHours((float) $group->sum('hours'));
                    }

                    if (in_array('user_total', $includeFields, true)) {
                        $row['user_total'] = $userTotal;
                    }

                    return $row;
                })
                ->values();
        })->values();
    }

    private function fieldValue(WorkHour $entry, string $field): string
    {
        return match ($field) {
            'client' => $entry->client?->name ?? 'No client',
            // `work_type` is kept as the FIELD KEY (saved report presets and the
            // Slack modal still send it) but its VALUE is now the work category.
            'work_type' => $this->workCategoryLabel($entry),
            'billable' => $entry->is_billable ? 'Billable' : 'Non-billable',
            'tracker' => filled($entry->tracker) ? $entry->tracker : 'Not specified',
            default => '',
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'user' => 'User',
            'client' => 'Client',
            'work_type' => 'Work Category',
            'billable' => 'Billable',
            'tracker' => 'Tracker',
            'hours' => 'Hours',
            'user_total' => 'User Total',
            default => ucfirst($field),
        };
    }

    private function tableTextCell(string $text): array
    {
        return [
            'type' => 'raw_text',
            'text' => $text,
        ];
    }

    private function tableBoldCell(string $text): array
    {
        return [
            'type' => 'rich_text',
            'elements' => [[
                'type' => 'rich_text_section',
                'elements' => [[
                    'type' => 'text',
                    'text' => $text,
                    'style' => ['bold' => true],
                ]],
            ]],
        ];
    }

    /**
     * ONE vocabulary source. The digest used to carry its own label map — the
     * one that renamed `upwork_bidding` to "Business development" while the
     * timesheet pages called the same value something else. It now prints the
     * workspace's own work_categories row name.
     *
     * No N+1: the relation is eager-loaded above, and the legacy fallback for a
     * row the backfill never reached goes through WorkCategory's memoised
     * per-workspace catalogue (at most one query per process).
     */
    private function workCategoryLabel(WorkHour $entry): string
    {
        if ($entry->relationLoaded('workCategory') && $entry->workCategory) {
            return $entry->workCategory->name;
        }

        $legacy = $entry->work_type;

        if ($legacy === null || $legacy === '') {
            return 'Not specified';
        }

        return WorkCategory::resolveForLegacyWorkType($legacy)?->name
            ?? ucwords(str_replace('_', ' ', $legacy));
    }

    private function formatHours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }
}
