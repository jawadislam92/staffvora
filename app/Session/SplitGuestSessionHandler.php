<?php

namespace App\Session;

use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Session\CookieSessionHandler;
use SessionHandlerInterface;

/**
 * A session handler with two sinks: the app's real one (file/database) for
 * sessions that carry state, and an encrypted cookie for the throwaway ones.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS.
 *
 * Production runs SESSION_DRIVER=file, and every request through the `web`
 * group ends in one `file_put_contents()` into storage/framework/sessions —
 * including the ones from a visitor who has no session, wants no session and
 * will never send the cookie back. An anonymous flood of unique URLs therefore
 * writes one file (and burns one inode) per request, forever, on a VPS this app
 * shares with other live businesses. It is the cache-flood hole one layer down:
 * same disk, same failure, different door.
 *
 * The obvious fix — drop StartSession from the public marketing routes — breaks
 * the things that make those pages work. The contact form's CSRF token lives in
 * the session; so does the login form's, so do flash messages and the validation
 * error bag. Sessionless marketing pages means a 419 on the contact form.
 *
 * So the question is not "session or no session", it is "WHERE does a session
 * that contains nothing worth protecting belong". The answer is: in the
 * visitor's own browser. That is what Laravel's shipped `cookie` driver already
 * does, and this handler routes to it per-request rather than per-deployment:
 *
 *   - a session whose entire contents are bootstrap keys goes into an encrypted
 *     cookie. Zero bytes on our disk, and the CSRF token still round-trips, so
 *     the contact form and the login form keep working exactly as before;
 *   - the moment anything else appears — a login, a flash message, an error
 *     bag, an impersonation marker, a feature nobody has written yet — the whole
 *     session goes to the real driver, exactly as it does today.
 *
 * The allow-list is the safe half of that decision and it is deliberately TINY,
 * because the property being relied on is that a cookie session can be REPLAYED
 * by whoever holds it and cannot be revoked server-side. `_token`, `_previous`,
 * `_flash` and `url` (the post-login intended redirect, always same-origin) are
 * all harmless to replay in the browser that owns them. Anything not on that
 * list is assumed to be security state and gets the server-side sink. Adding a
 * key to that list is a security decision, not a performance one.
 *
 * Note what is NOT weakened: session fixation is still handled by
 * `session()->regenerate()` on login, and an authenticated session is still a
 * server-side session that an operator can delete. A cookie payload is
 * encrypted AND bound to its cookie name by EncryptCookies, so a client can
 * only ever replay a payload we ourselves issued to it — never forge one.
 *
 * @see \App\Http\Middleware\LeanSessionStart for the routing decision itself.
 */
class SplitGuestSessionHandler extends CookieSessionHandler
{
    /**
     * Hard ceiling on what we are willing to push into a cookie.
     *
     * Browsers cap a cookie at ~4 KB. A disposable session cannot normally get
     * anywhere near that, but `url.intended` is `$request->fullUrl()` and a
     * caller controls the query string — so an 8 KB URL against any auth-only
     * route would mint an over-sized cookie. Over the ceiling we write NOWHERE:
     * dropping a session that provably holds nothing but a CSRF token costs the
     * visitor a fresh token on their next request, whereas falling back to the
     * disk sink would hand the flood back exactly the door it came from.
     */
    private const MAX_COOKIE_PAYLOAD = 3072;

    /** The real driver — file, database, whatever config/session.php names. */
    private SessionHandlerInterface $primary;

    /** Set per request by the middleware, just before the session is saved. */
    private bool $useCookie = false;

    /** Did this session's data come off the primary sink on the way in? */
    private bool $primaryHadData = false;

    public function __construct(SessionHandlerInterface $primary, CookieJar $cookie, int $minutes, bool $expireOnClose = false)
    {
        parent::__construct($cookie, $minutes, $expireOnClose);

        $this->primary = $primary;
    }

    /** Choose the sink for the write that is about to happen. */
    public function useCookieSink(bool $useCookie): void
    {
        $this->useCookie = $useCookie;
    }

    public function primary(): SessionHandlerInterface
    {
        return $this->primary;
    }

    /**
     * Read is deliberately NOT symmetric with write: it asks both sinks, primary
     * first. That keeps the routing decision stateless — nothing has to be
     * remembered between requests about which sink a given session id used, so a
     * session can move from cookie to disk (on login) and back (once a flash
     * message has been consumed) without a marker anywhere that could get out of
     * step with reality.
     */
    public function read($sessionId): string|false
    {
        $data = $this->primary->read($sessionId);

        if (is_string($data) && $data !== '') {
            $this->primaryHadData = true;

            return $data;
        }

        $this->primaryHadData = false;

        return parent::read($sessionId);
    }

    public function write($sessionId, $data): bool
    {
        if (! $this->useCookie) {
            // Promoting a cookie session to the real sink: forget the cookie
            // copy so the two can never disagree, but only when the browser
            // actually sent one (a freshly regenerated id has no cookie yet, and
            // an empty Set-Cookie per request is pure header bloat).
            if (! $this->primaryHadData && $this->requestHasCookie($sessionId)) {
                $this->cookie->queue($this->cookie->forget($sessionId));
            }

            return $this->primary->write($sessionId, $data);
        }

        // Demoting to the cookie sink: drop the server-side copy so a session
        // that has finished with its flash message stops occupying a file.
        if ($this->primaryHadData) {
            $this->primary->destroy($sessionId);
        }

        if (strlen((string) $data) > self::MAX_COOKIE_PAYLOAD) {
            return true; // see MAX_COOKIE_PAYLOAD — write nowhere, on purpose.
        }

        return parent::write($sessionId, $data);
    }

    public function destroy($sessionId): bool
    {
        $this->primary->destroy($sessionId);

        return parent::destroy($sessionId);
    }

    /**
     * Garbage collection belongs to the primary sink only. Cookie sessions
     * expire in the browser and carry their own `expires` stamp, which
     * CookieSessionHandler::read() enforces.
     */
    public function gc($lifetime): int
    {
        return $this->primary->gc($lifetime);
    }

    public function open($savePath, $sessionName): bool
    {
        return $this->primary->open($savePath, $sessionName) && parent::open($savePath, $sessionName);
    }

    public function close(): bool
    {
        return $this->primary->close() && parent::close();
    }

    private function requestHasCookie(string $sessionId): bool
    {
        return $this->request !== null && $this->request->cookies->has($sessionId);
    }
}
