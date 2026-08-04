<?php

namespace App\Http\Middleware;

use App\Session\SplitGuestSessionHandler;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\Store;

/**
 * StartSession, minus the part where an anonymous visitor gets to mint an
 * unbounded number of files in storage/framework/sessions.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE HOLE. Production runs SESSION_DRIVER=file and the public marketing routes
 * are in the `web` group, so every anonymous request — including the 404 from a
 * slug that has never existed — ends in a session file. 40 requests, 40 files.
 * A loop over random URLs is therefore an inode/disk exhaustion primitive
 * against the least protected surface in the product, on a VPS that co-hosts
 * other live businesses. The cache-flood equivalent was closed a round ago; this
 * is the same shape one layer down.
 *
 * WHAT THIS DOES NOT DO. It does not remove StartSession from any route. That
 * was the tempting fix and it is wrong: the marketing site's contact form is
 * CSRF-protected, and the CSRF token lives in the session. No session on the GET
 * that renders the form means a 419 on the POST that submits it — and the same
 * for /login, /register and /forgot-password. Flash messages and the validation
 * error bag come off the session too. Every one of those keeps working here
 * because the session still exists on every request; only its STORAGE moves.
 *
 * WHAT IT DOES. Before the session is written, it looks at what the session
 * actually contains. A session holding nothing but bootstrap keys is written to
 * an encrypted cookie in the visitor's own browser (Laravel's shipped `cookie`
 * driver, wired in per-request by SplitGuestSessionHandler) instead of to our
 * disk. A session holding anything else — a login, a flash message, an error
 * bag, an impersonation marker, a key some future feature adds — goes to the
 * real driver, byte for byte as it does today.
 *
 * That is the important property of the rule: it is an ALLOW-LIST, so it fails
 * SAFE. Anything unrecognised is treated as state worth protecting and gets the
 * server-side sink; nobody has to remember to update this file when they add a
 * feature, they only have to think about it if they want a new key to be
 * cookie-eligible — which is a security decision, and is why the list is four
 * entries long and each one is justified below.
 *
 * SCOPE. Engaged only for the drivers that persist per-session server-side
 * records (file, database). Under `array` (tests) and `redis` it is inert — a
 * subclass that changes nothing about how sessions behave. Registered by
 * swapping the container binding for StartSession rather than by editing the
 * middleware group, so the class string in $middlewarePriority still matches and
 * middleware ordering is untouched.
 */
class LeanSessionStart extends StartSession
{
    /**
     * The only keys a session may contain and still be treated as disposable.
     *
     * The test each one has to pass is not "is it small" but "is it harmless to
     * REPLAY", because a cookie session can be replayed by whoever holds it
     * until it expires and cannot be revoked from the server:
     *
     *   _token     the CSRF token. Bound to the browser holding the cookie; a
     *              stale one is no more useful to an attacker than a current
     *              one, and session fixation is still handled by the
     *              regenerate() on login (at which point the session stops
     *              being disposable and moves to the server-side sink anyway).
     *   _previous  the last URL visited, written by StartSession itself on every
     *              GET. Only ever read for back() redirects.
     *   _flash     the flash bookkeeping arrays. Note that flash *values* are
     *              stored as top-level keys, so a session with an actual flash
     *              message in it is NOT disposable and does not land here.
     *   url        `url.intended`, written by redirect()->guest() whenever an
     *              anonymous request touches an auth-only route. Always a URL on
     *              this origin. It has to be on the list: /dashboard is exactly
     *              as anonymous-floodable as a bogus marketing slug, and without
     *              this entry the flood simply moves one route over.
     */
    public const DISPOSABLE_KEYS = ['_token', '_previous', '_flash', 'url'];

    /** Drivers that write a per-session server-side record we want to bound. */
    private const BOUNDED_DRIVERS = ['file', 'database'];

    /**
     * Is this session safe to hand back to the browser instead of storing?
     *
     * @param  array<string, mixed>  $data
     */
    public static function isDisposable(array $data): bool
    {
        return array_diff(array_keys($data), self::DISPOSABLE_KEYS) === [];
    }

    public function getSession(Request $request)
    {
        $session = parent::getSession($request);

        if ($session instanceof Store) {
            $this->attachSplitHandler($session, $request);
        }

        return $session;
    }

    /**
     * Decide the sink immediately before the write.
     *
     * It has to be here rather than anywhere earlier: the session is only
     * finished being written to once the response has been produced, and a
     * request that looked disposable on the way in (GET /login) routinely is not
     * on the way out (POST /login, now carrying an auth key).
     */
    protected function saveSession($request)
    {
        $session = $this->manager->driver();

        if ($session instanceof Store) {
            $handler = $session->getHandler();

            if ($handler instanceof SplitGuestSessionHandler) {
                $handler->useCookieSink(self::isDisposable($session->all()));
            }
        }

        parent::saveSession($request);
    }

    private function attachSplitHandler(Store $session, Request $request): void
    {
        $config = $this->manager->getSessionConfig();

        if (! in_array($config['driver'] ?? null, self::BOUNDED_DRIVERS, true)) {
            return;
        }

        $handler = $session->getHandler();

        if ($handler instanceof SplitGuestSessionHandler) {
            $handler->setRequest($request);

            return;
        }

        $split = new SplitGuestSessionHandler(
            $handler,
            Container::getInstance()->make(CookieJar::class),
            (int) ($config['lifetime'] ?? 120),
            (bool) ($config['expire_on_close'] ?? false),
        );

        $split->setRequest($request);

        $session->setHandler($split);
    }
}
