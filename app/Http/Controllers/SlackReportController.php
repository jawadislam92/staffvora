<?php

namespace App\Http\Controllers;

use App\Services\SlackReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SlackReportController extends Controller
{
    public function store(Request $request, SlackReportService $slack): mixed
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'include_fields' => ['required', 'array', 'min:1'],
            'include_fields.*' => [
                'string',
                Rule::in(['client', 'work_type', 'tracker', 'hours', 'user_total']),
            ],
        ]);

        $start = Carbon::parse($validated['start_date'], config('services.slack_reports.timezone'))->startOfDay();
        $end = Carbon::parse($validated['end_date'], config('services.slack_reports.timezone'))->endOfDay();

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'end_date' => 'The Slack report range cannot exceed 366 days.',
            ]);
        }

        try {
            $summary = $slack->sendRange($start, $end, [
                'userIds' => $validated['user_ids'],
                'includeFields' => $validated['include_fields'],
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'slack' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            "Slack report sent for {$summary['start_date']} through {$summary['end_date']}."
        );
    }
}
