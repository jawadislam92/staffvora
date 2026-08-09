<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One recorded acceptance of the monitoring terms by one employee.
 *
 * This model is also the SINGLE SOURCE OF TRUTH for what the desktop agent
 * shows on its consent screen. The disclosure lives here — not in the desktop
 * repo — for two reasons:
 *
 *   1. The server decides which version is currently required, so publishing
 *      new terms makes the whole installed fleet re-ask on next launch without
 *      shipping a new build. That is requirement (4) — versioning designed in,
 *      not retrofitted.
 *   2. The exact text the employee was shown is hashed into their consent row,
 *      so "what did they actually agree to" is answerable years later even
 *      after the marketing pages have been edited.
 *
 * The desktop app carries a bundled copy of this text ONLY as an offline
 * fallback (desktop/main/consent.js). When the two disagree, the server wins
 * and the employee is asked again.
 *
 * @see database/migrations/2026_08_09_100000_create_desktop_consents_table.php
 */
class DesktopConsent extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    /**
     * The document version employees must currently have accepted.
     *
     * BUMP THIS whenever the terms, the privacy policy, or the disclosure
     * bullets below change in a way that alters what is being consented to.
     * Every agent re-asks on its next launch; nothing is auto-migrated, because
     * silently carrying an old consent forward onto new terms is precisely the
     * failure this feature exists to prevent.
     *
     * Date-based on purpose: an auditor asking "which terms did they accept?"
     * gets an answer they can line up against the published documents.
     */
    public const CURRENT_VERSION = '2026-08-09';

    /** Human-facing effective date of CURRENT_VERSION. */
    public const EFFECTIVE_DATE = '9 August 2026';

    /** Canonical public documents. Absolute — the agent may run off-domain. */
    public const TERMS_URL = 'https://staffvora.com/terms';

    public const PRIVACY_URL = 'https://staffvora.com/privacy';

    /** Acceptance surfaces. Only the desktop first-run exists today. */
    public const SOURCE_DESKTOP_FIRST_RUN = 'desktop_first_run';

    /**
     * The plain-language monitoring disclosure shown IMMEDIATELY ABOVE the
     * checkbox — before consent, not after it and not behind a link.
     *
     * Rules for editing this list, because it is the most trust-sensitive text
     * in the product:
     *   - say what is captured, in words a non-technical person reads once;
     *   - say what is NOT captured, because that is what people actually fear;
     *   - say who can see it;
     *   - never soften a capability into ambiguity ("usage data" is not an
     *     honest description of a screenshot);
     *   - bump CURRENT_VERSION in the same commit.
     *
     * @var list<array{title: string, body: string}>
     */
    public const DISCLOSURE = [
        [
            'title' => 'Working time',
            'body' => 'When you start and stop the tracker, how long you work, and the client or task you assign that time to.',
        ],
        [
            'title' => 'Activity level',
            'body' => 'How often you use the keyboard and mouse while the tracker is running — as counts, to produce an activity percentage. The keys you press are never recorded.',
        ],
        [
            'title' => 'Screenshots — only if your employer turns them on',
            'body' => 'Periodic pictures of your screen while the tracker is running. They can be blurred by your employer. They are never taken while the tracker is stopped or paused.',
        ],
        [
            'title' => 'Apps and websites — only if your employer turns them on',
            'body' => 'The name of the application or website you are working in, and the window title. Page contents, messages and passwords are not recorded.',
        ],
        [
            'title' => 'Your device',
            'body' => 'Your computer name, operating system and app version, so problems with the tracker can be diagnosed. On workspaces using Machine Compliance, the browser extensions and installed programs on this PC are also listed.',
        ],
        [
            'title' => 'Who can see it',
            'body' => 'Administrators and managers of your workspace, and Staffvora staff acting on their instructions to support the service. Your data is not sold and is not shared with other workspaces.',
        ],
        [
            'title' => 'When nothing is recorded',
            'body' => 'Stop the tracker and capture stops — no screenshots, no activity, no app names. Time you have not started is not tracked.',
        ],
    ];

    /** The exact sentence beside the checkbox. Stored server-side so the wording is auditable. */
    public const CHECKBOX_LABEL = 'I have read and agree to the Terms of Service and the Privacy Policy, and I understand what is monitored.';

    protected $table = 'desktop_consents';

    protected $fillable = [
        'organization_id',
        'user_id',
        'document_version',
        'disclosure_hash',
        'terms_url',
        'privacy_url',
        'source',
        'device_name',
        'platform',
        'app_version',
        'client_at',
        'accepted_at',
        'ip_address',
    ];

    protected $casts = [
        'client_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The full payload the agent needs to render the consent screen. Kept in
     * one place so the API response, the stored hash and any future web-side
     * consent screen cannot drift apart.
     *
     * @return array<string, mixed>
     */
    public static function documentPayload(): array
    {
        return [
            'version' => self::CURRENT_VERSION,
            'effective_date' => self::EFFECTIVE_DATE,
            'terms_url' => self::TERMS_URL,
            'privacy_url' => self::PRIVACY_URL,
            'checkbox_label' => self::CHECKBOX_LABEL,
            'disclosure' => self::DISCLOSURE,
            'disclosure_hash' => self::disclosureHash(),
        ];
    }

    /**
     * Stable fingerprint of the disclosure text for a version.
     *
     * Deliberately hashes the VERSION together with the text: two different
     * versions that happen to carry identical bullets must still be
     * distinguishable in the ledger.
     */
    public static function disclosureHash(): string
    {
        $canonical = self::CURRENT_VERSION."\n".self::CHECKBOX_LABEL."\n";

        foreach (self::DISCLOSURE as $item) {
            $canonical .= $item['title']."\n".$item['body']."\n";
        }

        return hash('sha256', $canonical);
    }
}
