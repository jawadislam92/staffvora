<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerOrgSlackTest extends TestCase
{
    use RefreshDatabase;

    public function test_slack_bot_token_is_per_org_and_stored_encrypted(): void
    {
        $a = Organization::create(['name' => 'A', 'slug' => 'a-'.Str::random(5)]);
        $b = Organization::create(['name' => 'B', 'slug' => 'b-'.Str::random(5)]);
        $current = app(CurrentOrganization::class);

        $current->runFor($a, fn () => MonitoringSetting::current()->update(['slack_bot_token' => 'xoxb-aaa']));
        $current->runFor($b, fn () => MonitoringSetting::current()->update(['slack_bot_token' => 'xoxb-bbb']));

        $this->assertSame('xoxb-aaa', $current->runFor($a, fn () => MonitoringSetting::current()->slack_bot_token));
        $this->assertSame('xoxb-bbb', $current->runFor($b, fn () => MonitoringSetting::current()->slack_bot_token));

        // The raw column is ciphertext, not the plaintext token.
        $raw = DB::table('monitoring_settings')->where('organization_id', $a->id)->value('slack_bot_token');
        $this->assertNotSame('xoxb-aaa', $raw);
        $this->assertNotEmpty($raw);
    }
}
