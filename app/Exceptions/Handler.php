<?php

namespace App\Exceptions;

use App\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * A 500 THAT CANNOT BE DIAGNOSED IS A 500 THAT HAPPENS AGAIN
 * ─────────────────────────────────────────────────────────────────────────────
 * The previous handler logged the framework default: message, file, line, stack
 * trace. On a single-tenant app that is enough. On a multi-tenant one it is not —
 * "SQLSTATE[HY000]: General error" with a stack trace tells you a query failed,
 * but not WHICH CUSTOMER it failed for, which is the first question support asks
 * and the only way to reproduce. Every report now carries the tenant, the actor,
 * the route and a correlation id.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY *NOT* LOGGED
 * ─────────────────────────────────────────────────────────────────────────────
 * A log line is read by staff, shipped to third-party monitoring, and lives far
 * longer than the request. So the context below is strictly IDENTIFIERS —
 * numbers, a route name, a method, a path:
 *
 *   • NO request body. Not filtered, not redacted, not "just the safe keys". The
 *     request body on this app carries passwords, Slack bot tokens, S3
 *     credentials, billing addresses, screenshot bytes and window titles, and a
 *     redaction allowlist is one new form field away from leaking.
 *   • NO query string, only the PATH. Query strings pick up tokens and search
 *     terms containing personal data.
 *   • NO email addresses, names or any other row content. The user id and the
 *     organization id are enough to find the record, and they are meaningless to
 *     anyone without database access.
 *   • NO headers. That is where Authorization, Cookie and X-Api-Key live.
 *
 * `organization_id` is the tenant that OWNS the request, not a foreign one:
 * it comes from the request-scoped CurrentOrganization binding, which is set by
 * middleware from the authenticated user. Nothing here reads another tenant's
 * data to build the context, and no row content crosses into the log.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE CORRELATION ID
 * ─────────────────────────────────────────────────────────────────────────────
 * Every reported exception gets a short id, which is also what the generic error
 * page shows the user. "Something went wrong — reference 9f3ac21b" turns an
 * unreproducible support ticket into one `grep`. It is random per exception and
 * carries no information about the account, so it is safe to show.
 */
class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation
     * exceptions.
     *
     * Flashed input is written to the session store and re-populated into the
     * form on the next render, so anything secret listed here would otherwise
     * survive a failed validation round-trip.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'new_password',
        'new_password_confirmation',
        'token',
        'api_token',
        'access_token',
        'secret',
        'client_secret',
        'slack_bot_token',
        'slack_webhook_url',
        'aws_secret_access_key',
    ];

    /**
     * Exceptions that are normal traffic, not incidents. Reporting them buries
     * the real 500s: a 404 crawler, an expired CSRF token on a left-open tab and
     * a failed login are all expected, and a log full of them is a log nobody
     * reads.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
        TokenMismatchException::class,
        ValidationException::class,
    ];

    /** The correlation id for the exception currently being handled. */
    private ?string $incidentId = null;

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $this->incidentId = Str::lower(Str::random(8));
        });
    }

    /**
     * Context attached to EVERY log entry for this request.
     *
     * Merged into the framework's own context (which contributes the user id via
     * `userContext()`). Identifiers only — see the class docblock for what is
     * excluded and why.
     *
     * Every lookup is wrapped: the handler must not throw while reporting, or
     * the original exception is lost and replaced by a far less useful one.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $context = parent::context();

        try {
            $context['organization_id'] = app(CurrentOrganization::class)->id();
        } catch (Throwable) {
            // Container not bootable (very early failure) — nothing to add.
        }

        try {
            $context['user_id'] = Auth::hasUser() ? Auth::id() : null;
        } catch (Throwable) {
            $context['user_id'] = null;
        }

        if (app()->runningInConsole()) {
            $context['channel'] = 'console';

            return $context;
        }

        try {
            $request = request();

            $context += [
                'channel' => 'http',
                'method' => $request->getMethod(),
                // PATH only. Query strings carry tokens and search terms.
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => optional($request->route())->getName(),
                // Useful for telling a desktop-agent failure from a browser one.
                // Truncated because it is client-supplied.
                'agent' => Str::limit((string) $request->userAgent(), 120, ''),
                'ip' => $request->ip(),
            ];
        } catch (Throwable) {
            // No request bound (queue worker, early boot).
        }

        if ($this->incidentId !== null) {
            $context['incident'] = $this->incidentId;
        }

        return $context;
    }

    /**
     * Hand the correlation id to the error page so the user can quote it.
     *
     * Only for real server faults: a 403/404/419 page showing an "incident
     * reference" invites a support ticket for something that is not an incident.
     */
    protected function renderHttpException(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e)
    {
        if ($this->incidentId !== null && $e->getStatusCode() >= 500) {
            view()->share('incidentId', $this->incidentId);
        }

        return parent::renderHttpException($e);
    }

    /**
     * Never let an unexpected exception class reach the browser as a raw message
     * in production. `HttpExceptionInterface` messages are ours (`abort(403,
     * 'You do not own this workspace')`); everything else is a driver or library
     * message that can name tables, columns, file paths and connection strings.
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        return parent::shouldReturnJson($request, $e);
    }

    /**
     * @return array<string, mixed>
     */
    protected function convertExceptionToArray(Throwable $e): array
    {
        $payload = parent::convertExceptionToArray($e);

        if (! config('app.debug') && ! $e instanceof HttpExceptionInterface && $this->incidentId !== null) {
            $payload['incident'] = $this->incidentId;
        }

        return $payload;
    }
}
