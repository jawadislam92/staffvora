<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BusinessTime;
use App\Support\CsvExport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REGRESSION GUARD — employee-supplied text must not escape its CSV cell, and
 * must not execute on the manager's machine.
 *
 * Clock-in notes and display names are both self-service. They land in the
 * MANAGER-facing attendance export, which is opened in Excel. The old
 * hand-rolled '"'.$value.'"' quoting let `Bob","=1+1","x` close its own cell and
 * invent columns (silently shifting the payroll columns), and a leading
 * = / + / - / @ made the spreadsheet EXECUTE the cell — `=cmd|' /C calc'!A1` is
 * a live DDE shell launch.
 *
 * Inverted from the audit's VerifierCsvInjectionProbeTest / VerifyCsvInjectionTest
 * / VerifyCsvCrossUserTest, which all asserted the vulnerable behaviour.
 */
class CsvExportInjectionDefenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_employee_payload_cannot_break_out_of_the_attendance_export_cells(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', BusinessTime::tz()));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => 'employee',
            'name' => 'Bob","=1+1","x',
        ]);

        $this->actingAs($employee)
            ->postJson('/time-entries', [
                'action_type' => 'clock_in',
                'notes' => 'ok","=cmd|\' /C calc\'!A1","tail',
            ])
            ->assertOk();

        $csv = $this->actingAs($admin)
            ->get('/employee-attendance/export?date='.Carbon::today(BusinessTime::tz())->toDateString())
            ->assertOk()
            ->streamedContent();

        // 1. No cell break-out: the quote inside the name is escaped ("" per
        //    RFC 4180), so the row keeps its eight columns.
        $this->assertStringNotContainsString('"Bob","=1+1","x","Employee"', $csv);
        $this->assertStringNotContainsString('"ok","=cmd|\' /C calc\'!A1","tail"', $csv);

        // 2. No live formula anywhere: every trigger is behind a text marker.
        $this->assertNoLiveFormula($csv);

        // 3. The payload is still PRESENT (defanged, not silently dropped) —
        //    managers must still be able to read what the employee typed.
        $this->assertStringContainsString('=cmd|', $csv);

        // 4. Header row and column count are unchanged.
        $lines = preg_split('/\r?\n/', trim($csv));
        $this->assertSame('Employee,Designation,Status,Work Hours,Break Hours,First Clock In,Last Action,Total Actions', $lines[0]);
        $this->assertCount(8, str_getcsv($lines[1]));
        $this->assertSame('Bob","=1+1","x', str_getcsv($lines[1])[0]);
    }

    public function test_own_timesheet_export_cells_are_neutralised(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', BusinessTime::tz()));

        $employee = User::factory()->create(['role' => 'employee', 'name' => 'Bob","=2+2","x']);

        $this->actingAs($employee)
            ->postJson('/time-entries', [
                'action_type' => 'clock_in',
                'notes' => 'ok","=HYPERLINK("http://evil.test","click")","tail',
            ])
            ->assertOk();

        $csv = $this->actingAs($employee)->get('/time-entries/export')->assertOk()->streamedContent();

        $this->assertNoLiveFormula($csv);

        $lines = preg_split('/\r?\n/', trim($csv));
        $this->assertSame('Date,Time,Employee,Action,Notes', $lines[0]);

        // The date "Jun 8, 2026" contains a comma; it must be one quoted cell,
        // not two columns. That mis-alignment is what payroll reads.
        $row = str_getcsv($lines[1]);
        $this->assertCount(5, $row);
        $this->assertSame('Jun 8, 2026', $row[0]);
        $this->assertSame('Bob","=2+2","x', $row[2]);
    }

    public function test_timesheet_export_stays_self_scoped(): void
    {
        // Refutation control carried over from the audit: an admin's own
        // timesheet export must never contain another user's text.
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', BusinessTime::tz()));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'role' => 'employee',
            'organization_id' => $admin->organization_id,
        ]);

        $this->actingAs($employee)
            ->postJson('/time-entries', ['action_type' => 'clock_in', 'notes' => 'PAYLOAD_FROM_EMPLOYEE'])
            ->assertSuccessful();

        $csv = $this->actingAs($admin)->get('/time-entries/export')->assertOk()->streamedContent();

        $this->assertStringNotContainsString('PAYLOAD_FROM_EMPLOYEE', $csv);
    }

    public function test_attendance_export_preserves_its_detailed_activity_sections(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', BusinessTime::tz()));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => 'employee',
            'name' => 'Plain Person',
        ]);

        $this->actingAs($employee)
            ->postJson('/time-entries', ['action_type' => 'clock_in', 'notes' => 'all good'])
            ->assertOk();

        $csv = $this->actingAs($admin)
            ->get('/employee-attendance/export?date='.Carbon::today(BusinessTime::tz())->toDateString())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("\nDetailed Activity:\n", $csv);
        $this->assertStringContainsString("Time,Action,Notes\n", $csv);
        $this->assertStringContainsString('Plain Person', $csv);
        $this->assertStringContainsString('all good', $csv);
        // Hour columns stay numeric so payroll can sum them.
        $this->assertMatchesRegularExpression('/,0\.00,0\.00,/', $csv);
    }

    public function test_cell_neutralisation_rules(): void
    {
        // Formula triggers are defanged...
        foreach (['=1+1', '+1+1', '-1+1', '@SUM(A1)', "\t=1+1", "\r=1+1"] as $payload) {
            $this->assertSame("'".$payload, CsvExport::cell($payload), $payload);
        }

        // ...but numbers and the "-" placeholder stay exactly as they were, so
        // no visible column changes for managers.
        $this->assertSame('-', CsvExport::cell('-'));
        $this->assertSame('0.00', CsvExport::cell('0.00'));
        $this->assertSame('-1.50', CsvExport::cell('-1.50'));
        $this->assertSame('1,234.00', CsvExport::cell('1,234.00'));
        $this->assertSame('', CsvExport::cell(null));
        $this->assertSame('Clock in', CsvExport::cell('Clock in'));
    }

    /**
     * A cell is "live" when, after RFC 4180 parsing, its first character is a
     * formula trigger. Parsing rather than substring-matching is the point: it
     * proves the payload could not have escaped its cell either.
     */
    private function assertNoLiveFormula(string $csv): void
    {
        foreach (preg_split('/\r?\n/', $csv) as $line) {
            if (trim($line) === '') {
                continue;
            }

            foreach (str_getcsv($line) as $cell) {
                // Live = starts with = + @ tab or CR, or a "-" that is not just
                // a lone placeholder and is not a negative number.
                $this->assertDoesNotMatchRegularExpression(
                    '/^(?:[=+@\t\r]|-(?!\d)(?!$))/',
                    (string) $cell,
                    'Live spreadsheet formula reached a CSV cell: '.$cell
                );
            }
        }
    }
}
