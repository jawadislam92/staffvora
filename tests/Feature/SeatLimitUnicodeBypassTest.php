<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OPEN HOLE — the demo-identity seat exclusion can still be forged.
 *
 * The seat exclusion has TWO halves that must describe exactly the same set of
 * addresses:
 *
 *   • the REFUSAL, {@see User::isReservedDemoEmail()} — `str_ends_with()` on
 *     `strtolower(trim($email))`, i.e. a BYTE-EXACT, ASCII-only comparison;
 *   • the EXCLUSION, {@see User::scopeExcludingDemoIdentities()} — two SQL
 *     `LIKE` predicates, which are evaluated in the COLUMN'S COLLATION.
 *
 * `config/database.php` sets the MySQL connection to `utf8mb4_unicode_ci`, and
 * a *_ci collation folds case AND accents: under it
 * `'…@demo.staffvora.İnvalid' LIKE '%@demo.staffvora.invalid'` is TRUE, while
 * PHP's `str_ends_with` on the same two byte strings is FALSE.
 *
 * So an address like `demo-9-1@demo.staffvora.İnvalid` (U+0130) passes
 * Laravel's `email` rule, passes `reservedEmailRule()`, passes the model guard
 * in `User::booted()` — and is then EXCLUDED from `User::seatUsage()` and from
 * `BillingPricing::billableSeats()`. It is a real, login-capable, password-
 * carrying account that costs no seat and appears on no invoice. Proven on
 * MySQL 8.4/utf8mb4_unicode_ci: a 2-seat workspace reached 9 active users with
 * both counters still reporting 1, via the ordinary `POST /users` form and via
 * `POST /onboarding/team`.
 *
 * SQLite (this suite's default) does no such folding, which is exactly why the
 * fixer's tests are green. The first test below therefore asks the LIVE
 * connection whether it folds — it passes on SQLite and FAILS on MySQL, so it
 * is the regression that actually guards production. The second test is
 * database-independent and documents the gap itself.
 */
class SeatLimitUnicodeBypassTest extends TestCase
{
    use RefreshDatabase;

    /** Addresses whose bytes differ from the reserved domain but fold onto it. */
    private const FOLDING_VARIANTS = [
        'dotted capital I (U+0130)' => "demo-9-1@demo.staffvora.\xC4\xB0nvalid",
        'a-acute'                   => "demo-9-2@demo.staffvor\xC3\xA1.invalid",
        'e-acute'                   => "demo-9-3@d\xC3\xA9mo.staffvora.invalid",
        'n-tilde'                   => "demo-9-4@demo.staffvora.i\xC3\xB1valid",
        'i-diaeresis'               => "demo-9-5@demo.staffvora.\xC3\xAFnvalid",
        'a-ring'                    => "demo-9-6@demo.st\xC3\xA5ffvora.invalid",
        'long s (U+017F)'           => "demo-9-7@demo.\xC5\xBFtaffvora.invalid",
        'sharp s (U+00DF)'          => "demo-9-8@demo.\xC3\x9Ftaffvora.invalid",
    ];

    /**
     * THE REGRESSION. Every address this connection's LIKE folds onto the
     * reserved domain must also be refused by the model guard — otherwise it is
     * a free, unbilled seat. Green on SQLite; red on MySQL utf8mb4_unicode_ci.
     */
    public function test_no_address_the_database_treats_as_reserved_escapes_the_guard(): void
    {
        $exploitable = [];

        foreach (self::FOLDING_VARIANTS as $label => $email) {
            $foldedByTheDatabase = (bool) DB::selectOne(
                'select (? like ?) as m',
                [$email, '%@'.User::DEMO_EMAIL_DOMAIN]
            )->m;

            if ($foldedByTheDatabase && ! User::isReservedDemoEmail($email)) {
                $exploitable[] = $label;
            }
        }

        $this->assertSame(
            [],
            $exploitable,
            'FREE SEATS: these addresses are excluded from seatUsage()/billableSeats() by the '
            .'SQL scope but accepted by the model guard: '.implode(', ', $exploitable)
        );
    }

    /**
     * THE GAP ITSELF, with no database involved: the guard compares bytes, so a
     * single non-ASCII codepoint anywhere in the reserved domain walks past it.
     * This test PASSES today, which is what "the hole is open" looks like — it
     * should be inverted to `assertTrue` once the guard is made fold-aware.
     */
    public function test_the_marker_guard_is_byte_exact_and_therefore_forgeable(): void
    {
        foreach (self::FOLDING_VARIANTS as $label => $email) {
            $this->assertFalse(
                User::isReservedDemoEmail($email),
                "[{$label}] is unexpectedly refused — has the guard been made fold-aware?"
            );

            // …and every one of them is the reserved domain with exactly ONE
            // codepoint substituted, which is all a *_ci collation forgives.
            $domain = mb_substr($email, mb_strpos($email, '@') + 1);
            $reserved = User::DEMO_EMAIL_DOMAIN;
            $this->assertSame(
                mb_strlen($reserved),
                mb_strlen($domain),
                "[{$label}] should be a one-codepoint substitution of the reserved domain"
            );

            $differences = 0;
            for ($i = 0; $i < mb_strlen($reserved); $i++) {
                if (mb_substr($domain, $i, 1) !== mb_substr($reserved, $i, 1)) {
                    $differences++;
                }
            }
            $this->assertSame(1, $differences, "[{$label}] should differ by exactly one codepoint");
        }
    }
}
