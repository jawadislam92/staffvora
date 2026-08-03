<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV cell hygiene for exports that managers open in Excel / LibreOffice /
 * Google Sheets.
 *
 * Two separate problems, both reachable by any ordinary employee through
 * self-service fields (a clock-in note, their own display name):
 *
 * 1. QUOTING. Hand-rolled '"'.$value.'"' concatenation lets a value containing
 *    a double quote close its own cell and invent new columns, which silently
 *    corrupts payroll columns. fputcsv() over php://output is the only correct
 *    answer — it escapes quotes, commas and newlines per RFC 4180. Use
 *    {@see stream()} rather than building a string by hand.
 *
 * 2. FORMULA INJECTION. A cell whose first character is =, +, -, @, TAB or CR
 *    is executed as a FORMULA when the file is opened. `=cmd|' /C calc'!A1` is
 *    a live DDE shell launch on the manager's own machine, and
 *    `=HYPERLINK(...)` exfiltrates neighbouring cells on a single click.
 *    Quoting does NOT help — the spreadsheet strips the quotes first. Each cell
 *    has to be neutralised individually; see {@see cell()}.
 */
class CsvExport
{
    /**
     * Neutralise one cell's value for spreadsheet consumption.
     *
     * A leading apostrophe is the universally understood "this cell is text"
     * marker, so the payload is displayed instead of executed.
     *
     * Plain numbers are deliberately left alone: payroll reads the hours
     * columns numerically, and prefixing a negative number would turn it into
     * text. A bare number can never be a formula, so this is safe.
     */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if ($value === '') {
            return '';
        }

        if (! preg_match('/^[=+\-@\t\r]/', $value)) {
            return $value;
        }

        // A lone trigger character has nothing to execute — "-" is this app's
        // "no clock-in yet" placeholder and every spreadsheet imports it as
        // text. Escaping it would put a stray apostrophe in a column managers
        // read every day.
        if (mb_strlen($value) === 1) {
            return $value;
        }

        // -1.50, +3, 1,234.00 — numeric, not a formula. Keep as-is so the hours
        // columns stay numeric in the sheet (payroll sums them).
        if (preg_match('/^[+-]?[\d,]+(?:\.\d+)?$/', $value)) {
            return $value;
        }

        return "'".$value;
    }

    /**
     * Write one row, every cell neutralised, using fputcsv's RFC 4180 quoting.
     *
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function row($handle, array $row): void
    {
        fputcsv($handle, array_map(static fn ($v) => self::cell($v), $row));
    }

    /**
     * Write a row VERBATIM — for fixed literal separators/labels that are not
     * user data (e.g. a "Detailed Activity:" section heading). Never pass
     * user-supplied text here.
     *
     * @param  resource  $handle
     */
    public static function literal($handle, string $line): void
    {
        fwrite($handle, $line);
    }

    /**
     * Stream a CSV download. Streaming (rather than building one big string)
     * keeps memory flat at 500 orgs x 100+ users x ~1M rows.
     *
     * @param  callable(resource): void  $writer
     */
    public static function stream(string $filename, callable $writer): StreamedResponse
    {
        return response()->stream(function () use ($writer) {
            $handle = fopen('php://output', 'w');
            $writer($handle);
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
