<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * A 500 THAT CANNOT BE DIAGNOSED IS A 500 THAT HAPPENS AGAIN…
 * ─────────────────────────────────────────────────────────────────────────────
 * On a multi-tenant product, "SQLSTATE[HY000]: General error" plus a stack trace
 * says a query failed but not WHICH CUSTOMER it failed for — the first question
 * support asks and the only way to reproduce. So every report carries the tenant,
 * the actor, the method, the path and a correlation id the user can quote.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * …BUT A LOG LINE IS ALSO THE EASIEST PLACE TO LEAK EVERYTHING
 * ─────────────────────────────────────────────────────────────────────────────
 * Logs are read by staff, shipped to third-party monitoring, and outlive the
 * request by months. The request body on this app carries passwords, Slack bot
 * tokens, S3 credentials, billing addresses and window titles; query strings pick
 * up reset tokens and search terms; headers carry Authorization and Cookie.
 * The handler therefore logs IDENTIFIERS ONLY, and this file is what stops the
 * next "just add the payload, it makes debugging easier" commit.
 *
 * The test posts a real password and a real `?token=` and then asserts neither
 * appears anywhere in the logged context — an assertion that would fail the
 * moment anyone widens the handler to a redaction allowlist, which is always one
 * new form field away from leaking.
 */
class ExceptionContextTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_VALUE = 'correct-horse-battery-staple';

    private const TOKEN_VALUE = 'tok_live_51QsecretQUERYSTRING';

    private const HEADER_VALUE = 'Bearer sk_live_headerSECRETvalue';

    /** @var array<int, MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // PHPUnit runs under the CLI SAPI, so `app()->runningInConsole()` is TRUE
        // inside a feature test and the handler takes its CONSOLE branch — which
        // returns before it ever adds method/path/route. Without this, every
        // assertion below about the HTTP context would silently test nothing.
        // The flag is memoised on the Application, so it is set (and restored)
        // by reflection rather than by env.
        $this->pretendNotRunningInConsole(false);

        // Side effect of the line above: VerifyCsrfToken's test bypass is also
        // keyed on runningInConsole(), so every POST below would 419. CSRF is not
        // what this file is about, so the middleware is dropped explicitly rather
        // than worked around with session tokens.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        // Capture what the handler logs without writing to storage/logs. The
        // MessageLogged event fires from Illuminate\Log\Logger regardless of
        // which channel the message lands in, so a null channel still reports.
        config(['logging.default' => 'null']);

        Event::listen(MessageLogged::class, function (MessageLogged $message) {
            $this->logged[] = $message;
        });

        Route::middleware(['web', 'auth'])->post('/__test/incident', function () {
            throw new \RuntimeException('deliberate failure for the incident-context test');
        })->name('test.incident');

        Route::middleware(['web', 'auth'])->post('/__test/incident-json', function () {
            throw new \RuntimeException('deliberate failure for the incident-context test');
        });
    }

    protected function tearDown(): void
    {
        $this->pretendNotRunningInConsole(true);

        parent::tearDown();
    }

    private function pretendNotRunningInConsole(bool $value): void
    {
        $property = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, $value);
    }

    /** The context of the single reported incident. */
    private function incidentContext(): array
    {
        $errors = array_values(array_filter(
            $this->logged,
            fn (MessageLogged $m) => $m->level === 'error'
        ));

        $this->assertNotEmpty($errors, 'The failure was never reported at all.');

        return $errors[0]->context;
    }

    /** Everything the log line carries, flattened, minus the exception object itself. */
    private function flattenedContext(): string
    {
        $context = $this->incidentContext();
        unset($context['exception']);

        return print_r($context, true);
    }

    /** A workspace whose data must never appear in another workspace's incident. */
    private function foreignWorkspace(): array
    {
        $org = Organization::factory()->create([
            'name' => 'Foreign Tenant Ltd',
            'status' => Organization::STATUS_ACTIVE,
        ]);

        $user = app(CurrentOrganization::class)->runFor($org, fn () => User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Foreign Person',
            'email' => 'foreign-person@other-tenant.test',
        ]));

        return [$org, $user];
    }

    public function test_a_rendered_failure_logs_the_tenant_the_actor_the_route_and_an_incident_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/__test/incident', ['note' => 'hello']);

        $response->assertStatus(500);

        $context = $this->incidentContext();

        $this->assertSame(
            $user->organization_id,
            $context['organization_id'] ?? null,
            'Without the tenant, an exception report cannot be tied to a customer or reproduced.'
        );
        $this->assertSame($user->id, $context['user_id'] ?? null);
        $this->assertSame('http', $context['channel'] ?? null);
        $this->assertSame('POST', $context['method'] ?? null);
        $this->assertSame('/__test/incident', $context['path'] ?? null);
        $this->assertSame('test.incident', $context['route'] ?? null);

        $this->assertArrayHasKey('incident', $context, 'Every report needs a correlation id to grep for.');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $context['incident']);
    }

    /**
     * The correlation id shown to the user has to be the SAME one in the log, or it
     * is decoration rather than a support tool.
     */
    public function test_the_incident_id_shown_to_the_user_matches_the_one_in_the_log(): void
    {
        config(['app.debug' => false]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/__test/incident-json', []);

        $response->assertStatus(500);

        $shown = $response->json('incident');

        $this->assertNotNull($shown, 'A 500 must hand the user a reference they can quote.');
        $this->assertSame($this->incidentContext()['incident'] ?? null, $shown);
    }

    /**
     * THE LEAK TEST. A password in the body, a token in the query string and a
     * credential in a header — none of them may survive into the log.
     */
    public function test_the_incident_context_carries_no_request_body_no_query_string_and_no_headers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders(['Authorization' => self::HEADER_VALUE, 'X-Api-Key' => self::HEADER_VALUE])
            ->post('/__test/incident?token='.self::TOKEN_VALUE.'&q=confidential-search-term', [
                'password' => self::PASSWORD_VALUE,
                'password_confirmation' => self::PASSWORD_VALUE,
                'slack_bot_token' => 'xoxb-should-never-be-logged',
                'aws_secret_access_key' => 'AKIAsecretkeymaterial',
            ])
            ->assertStatus(500);

        $flat = $this->flattenedContext();

        foreach ([
            self::PASSWORD_VALUE,
            self::TOKEN_VALUE,
            self::HEADER_VALUE,
            'xoxb-should-never-be-logged',
            'AKIAsecretkeymaterial',
            'confidential-search-term',
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $flat,
                'A secret reached the exception log. The handler must log IDENTIFIERS ONLY — no body, '.
                'no query string, no headers. A redaction allowlist is not a substitute; it is one new '.
                'form field away from leaking.'
            );
        }

        // Belt and braces on the shape, not just the values: these keys must not exist.
        $context = $this->incidentContext();
        foreach (['input', 'request', 'body', 'query', 'headers', 'payload', 'parameters'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $context);
        }

        // The path is logged; the query string that came with it is not.
        $this->assertSame('/__test/incident', $context['path'] ?? null);
        $this->assertStringNotContainsString('?', (string) ($context['path'] ?? ''));
    }

    /**
     * The context is built from the request-scoped tenant binding, never by reading
     * around it. Another workspace's rows must not be anywhere near an incident
     * report — not its id in place of ours, and not its people's names or emails.
     */
    public function test_the_incident_context_never_carries_another_tenants_data(): void
    {
        // Our workspace first: the factory falls back to "the oldest organization"
        // when nothing is bound, so creating the foreign one first would put the
        // acting user inside it and quietly make this test assert nothing.
        $user = User::factory()->create();

        [$foreignOrg, $foreignUser] = $this->foreignWorkspace();
        $this->assertNotSame($foreignOrg->id, $user->organization_id);

        $this->actingAs($user)->post('/__test/incident', [])->assertStatus(500);

        $context = $this->incidentContext();
        $flat = $this->flattenedContext();

        $this->assertSame($user->organization_id, $context['organization_id']);
        $this->assertStringNotContainsString($foreignUser->email, $flat);
        $this->assertStringNotContainsString($foreignUser->name, $flat);
        $this->assertStringNotContainsString('Foreign Tenant Ltd', $flat);

        // No row content of our OWN tenant either — an id is enough to find the
        // record and is meaningless without database access.
        $this->assertStringNotContainsString($user->email, $flat);
    }

    /**
     * Normal traffic is not an incident. A log full of 404s and expired CSRF tokens
     * is a log nobody reads, which is how a real 500 goes unnoticed for a week.
     */
    public function test_ordinary_traffic_is_not_reported_as_an_incident(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/__test/definitely-not-a-route')->assertStatus(404);

        $errors = array_filter($this->logged, fn (MessageLogged $m) => $m->level === 'error');

        $this->assertEmpty(
            $errors,
            'A 404 was reported as an error. 404s, 419s, failed logins and validation failures are '.
            'expected traffic; reporting them buries the exceptions that matter.'
        );
    }

    /**
     * An un-authenticated failure still has to be reportable — the handler must not
     * throw while building context when there is no user and no tenant, or the
     * original exception is swallowed and replaced by a far less useful one.
     */
    public function test_a_failure_with_no_authenticated_user_still_reports_cleanly(): void
    {
        Route::get('/__test/incident-guest', function () {
            throw new \RuntimeException('deliberate guest failure');
        })->middleware('web');

        $this->get('/__test/incident-guest')->assertStatus(500);

        $context = $this->incidentContext();

        $this->assertArrayHasKey('organization_id', $context);
        $this->assertNull($context['organization_id']);
        $this->assertArrayHasKey('user_id', $context);
        $this->assertNull($context['user_id']);
        $this->assertSame('/__test/incident-guest', $context['path'] ?? null);
        $this->assertArrayHasKey('incident', $context);
    }

    /**
     * A failure inside a scheduled command (`tenant:run …`) is exactly the kind
     * nobody is watching, so the tenant still has to be identifiable.
     *
     * ⚠️ KNOWN GAP, deliberately not asserted here: the handler's console branch
     * `return`s before the incident id is attached, so console-channel reports
     * carry no correlation id. `app/Exceptions/Handler.php` is owned by another
     * work item; this is flagged in the handoff rather than patched here.
     */
    public function test_a_console_failure_still_identifies_the_tenant(): void
    {
        $this->pretendNotRunningInConsole(true);

        $org = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        app(CurrentOrganization::class)->runFor($org, function () {
            report(new \RuntimeException('deliberate console failure'));
        });

        $context = $this->incidentContext();

        $this->assertSame('console', $context['channel'] ?? null);
        $this->assertSame($org->id, $context['organization_id'] ?? null);
        $this->assertArrayNotHasKey('path', $context);
    }
}
