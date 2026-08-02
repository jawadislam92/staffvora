<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\FeedbackMessage;
use App\Models\User;
use App\Services\FeedbackSlackNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The feedback / request inbox, as lightweight tickets. Anyone files a request
 * (a question, a feature wish, a bug, or "the Help centre didn't answer this").
 * Each request is a THREAD: the opening post plus replies from the submitter and
 * managers (feedback.manage). Read state is tracked per side (the submitter and
 * the manager team) so each gets a "you have a reply" badge and a
 * "Seen / not read yet" receipt.
 *
 * Tenancy: Feedback + FeedbackMessage carry the BelongsToOrganization trait, so
 * every read here is already restricted to the active workspace and every write
 * is stamped with it. Nothing in this controller touches organization_id, and
 * route-model binding on {feedback} 404s across tenants automatically.
 */
class FeedbackController extends Controller
{
    /**
     * Run work once the response has already been flushed to the submitter.
     *
     * Registered on the container (not the queue) so the active tenant binding
     * survives — see the note at the call site in store().
     */
    private function afterResponse(callable $work): void
    {
        app()->terminating($work);
    }

    private function canManage(Request $request): bool
    {
        $user = $request->user();

        return $user->isSuperAdmin() || $user->hasPermission('feedback.manage');
    }

    public function index(Request $request): Response
    {
        $canManage = $this->canManage($request);
        $viewer = $request->user();

        $query = Feedback::with([
            'user:id,name,avatar',
            'handler:id,name',
            'messages.author:id,name,avatar',
        ])->latest('updated_at');

        // Without the triage permission a person only ever sees their own tickets.
        if (! $canManage) {
            $query->where('user_id', $viewer->id);
        }

        $records = $query->limit(300)->get();

        return Inertia::render('Feedback/Index', [
            'items' => $records->map(fn (Feedback $f) => $this->present($f, $viewer, $canManage))->values(),
            'canManage' => $canManage,
            'counts' => [
                'total' => $records->count(),
                'open' => $records->whereIn('status', Feedback::OPEN_STATUSES)->count(),
                'by_status' => $records->groupBy('status')->map->count(),
            ],
        ]);
    }

    /** Shape one ticket for the page, including viewer-specific read state. */
    private function present(Feedback $f, User $viewer, bool $canManage): array
    {
        $viewerIsSubmitter = $f->user_id === $viewer->id;

        // Most recent reply from a manager (anyone other than the submitter).
        $lastManagerAt = optional($f->messages->where('user_id', '!=', $f->user_id)->last())->created_at;
        // Most recent submitter activity = the opening request, plus their replies.
        $lastSubmitterAt = $f->messages->where('user_id', $f->user_id)->pluck('created_at')
            ->push($f->created_at)->filter()->max();

        $unread = $viewerIsSubmitter
            ? ($lastManagerAt !== null && ($f->response_seen_at === null || $lastManagerAt->gt($f->response_seen_at)))
            : ($f->manager_seen_at === null || ($lastSubmitterAt !== null && $lastSubmitterAt->gt($f->manager_seen_at)));

        // For the manager view: has the submitter read the latest manager reply?
        $submitterReadLatest = $lastManagerAt === null
            ? null
            : ($f->response_seen_at !== null && $f->response_seen_at->gte($lastManagerAt));

        $lastActivity = $f->messages->max('created_at') ?? $f->created_at;

        return [
            'id' => $f->id,
            'type' => $f->type,
            'subject' => $f->subject,
            'message' => $f->message,
            'context' => $f->context,
            'status' => $f->status,
            'created_at' => $f->created_at?->toIso8601String(),
            'last_activity_at' => $lastActivity?->toIso8601String(),
            'user' => $f->user ? ['id' => $f->user->id, 'name' => $f->user->name, 'avatar_url' => $f->user->avatar_url] : null,
            'handler' => $f->handler ? ['name' => $f->handler->name] : null,
            'messages' => $f->messages->map(fn (FeedbackMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
                'from_submitter' => $m->user_id === $f->user_id,
                'author' => $m->author ? ['id' => $m->author->id, 'name' => $m->author->name, 'avatar_url' => $m->author->avatar_url] : null,
            ])->values(),
            'unread' => $unread,
            'submitter_read_latest' => $submitterReadLatest,
        ];
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(Feedback::TYPES)],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:4000'],
            'context' => ['nullable', 'array'],
            'context.query' => ['nullable', 'string', 'max:200'],
            'context.url' => ['nullable', 'string', 'max:300'],
        ]);

        // organization_id is stamped by BelongsToOrganization from the active tenant.
        $item = Feedback::create([
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'context' => $data['context'] ?? null,
            'status' => 'new',
        ]);

        // Push it at the people who can act on it — an inbox nobody opens is an
        // inbox nobody answers. Best-effort; never blocks the submission.
        //
        // Deferred to after the response: the notifier makes blocking outbound
        // Slack calls, so running it inline made the submitter wait on Slack's
        // latency (up to a 10s timeout per call) and turned a slow Slack into a
        // 504 on filing a request. App::terminating rather than a queued job on
        // purpose — QUEUE_CONNECTION defaults to `sync`, so dispatching would
        // change nothing, and a real worker would run with NO tenant bound,
        // where OrganizationScope emits no predicate and the fan-out would
        // leak one workspace's feedback into another's DMs. Terminating
        // callbacks keep the request container, and its bound organization,
        // intact.
        $this->afterResponse(fn () => app(FeedbackSlackNotifier::class)->newRequest($item->load('user')));

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'id' => $item->id, 'message' => 'Thanks — your request landed in the team inbox.']);
        }

        return back()->with('success', 'Thanks — your request landed in the team inbox.');
    }

    /** Post a reply into a ticket's thread (submitter on own, or any manager). */
    public function reply(Request $request, Feedback $feedback): RedirectResponse
    {
        $canManage = $this->canManage($request);
        $isOwner = $feedback->user_id === $request->user()->id;
        abort_unless($canManage || $isOwner, 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'status' => ['nullable', Rule::in(Feedback::STATUSES)],
        ]);

        $feedback->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        // The author has, by definition, just seen the thread; mark THEIR side
        // read and leave the OTHER side unread so it badges. A manager reply also
        // stamps who handled it (and an optional status change).
        if ($canManage && ! $isOwner) {
            $update = ['handled_by' => $request->user()->id, 'handled_at' => now(), 'manager_seen_at' => now()];
            if (! empty($data['status'])) {
                $update['status'] = $data['status'];
            }
            $feedback->update($update);

            // Close the loop: the person who asked shouldn't have to keep
            // checking the inbox to find out they got an answer. Same deferral
            // as store() — replying also fires blocking Slack calls.
            $responder = $request->user();
            $this->afterResponse(fn () => app(FeedbackSlackNotifier::class)
                ->replied($feedback->load('user'), $data['body'], $responder));
        } else {
            $feedback->update(['response_seen_at' => now()]);
        }

        return back()->with('success', 'Reply sent.');
    }

    /** Mark this ticket read for whoever opened it (clears their badge). */
    public function seen(Request $request, Feedback $feedback): RedirectResponse
    {
        $canManage = $this->canManage($request);
        $isOwner = $feedback->user_id === $request->user()->id;
        abort_unless($canManage || $isOwner, 403);

        $feedback->update($isOwner ? ['response_seen_at' => now()] : ['manager_seen_at' => now()]);

        return back();
    }

    /** Status change (managers). An optional note rides along as a reply. */
    public function update(Request $request, Feedback $feedback): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(Feedback::STATUSES)],
            'response' => ['nullable', 'string', 'max:4000'],
        ]);

        if (! empty($data['response'])) {
            $feedback->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $data['response'],
            ]);
        }

        $feedback->update([
            'status' => $data['status'],
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
            'manager_seen_at' => now(),
        ]);

        return back()->with('success', 'Request updated.');
    }
}
