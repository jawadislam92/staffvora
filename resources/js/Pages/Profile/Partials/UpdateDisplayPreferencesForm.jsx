import { useEffect, useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Transition } from '@headlessui/react';
import { Button, Field, Select, Tabs } from '@/Components/ui';
import {
    TIMEZONE_GROUPS,
    TIMEZONE_OPTIONS,
    deviceTimezone,
    formatClock,
    formatClockDate,
    timezoneCityLabel,
    timezoneOffsetLabel,
} from '@/Utils/timeUtils';

// ── DEV_SAMPLE ─────────────────────────────────────────────────────────────
// Offline fixture for `preview` mode so the card renders without shared props.
const DEV_SAMPLE = {
    preferences: { override: false, timezone: null, format: null },
    workspaceTimezone: 'Asia/Karachi',
    workspaceFormat: '12',
};

const FORMAT_TABS = [
    { key: '12', label: '12-hour · 3:45 PM' },
    { key: '24', label: '24-hour · 15:45' },
];

/**
 * Per-viewer display preferences: the time zone and 12/24h format THIS person
 * reads times in. It changes nothing about how times are stored, and nothing
 * about which attendance day work buckets into — the server keeps using the
 * workspace's business timezone for that. Saved values come back on the shared
 * `display` prop, so every page re-renders in the chosen zone.
 */
export default function UpdateDisplayPreferencesForm({
    preferences,
    preview = false,
    className = '',
}) {
    const page = usePage().props || {};

    const prefs = (preview ? DEV_SAMPLE.preferences : preferences) || {
        override: false,
        timezone: null,
        format: null,
    };

    // The workspace-wide display default this person falls back to when they
    // don't set an override. teamSettings is the workspace display setting;
    // workspace.timezone (the business zone) is the last-resort fallback.
    const workspaceTimezone = preview
        ? DEV_SAMPLE.workspaceTimezone
        : page.teamSettings?.display_timezone || page.workspace?.timezone || 'UTC';
    const workspaceFormat = preview
        ? DEV_SAMPLE.workspaceFormat
        : String(page.teamSettings?.time_format || page.display?.format || '12') === '24'
          ? '24'
          : '12';

    const { data, setData, patch, processing, recentlySuccessful, errors } = useForm({
        // '' means "follow the workspace default".
        display_timezone: prefs.override && prefs.timezone ? prefs.timezone : '',
        time_format:
            String(prefs.override ? prefs.format || workspaceFormat : workspaceFormat) === '24'
                ? '24'
                : '12',
    });

    // Effective values for the live preview below the controls.
    const effectiveTimezone = data.display_timezone || workspaceTimezone;
    const hour12 = data.time_format !== '24';

    // Ticking clock so the preview is unmistakably live.
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(id);
    }, []);

    // A stored zone we don't list (set earlier, or from another client) must
    // still be selectable, otherwise saving would silently change it.
    const extraZone = useMemo(() => {
        const tz = data.display_timezone;
        return tz && !TIMEZONE_OPTIONS.includes(tz) ? tz : null;
    }, [data.display_timezone]);

    // The device zone is a real IANA id, so it is always a valid choice even
    // when it isn't one of the zones we list (it lands in the "Current" group).
    const device = deviceTimezone();
    const canUseDevice = Boolean(device) && device !== effectiveTimezone;

    const submit = (e) => {
        e.preventDefault();
        if (preview) return;
        patch(route('profile.display'), { preserveScroll: true });
    };

    return (
        <section className={className}>
            <form onSubmit={submit} className="space-y-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <Field
                        label="Time zone"
                        htmlFor="display_timezone"
                        error={errors.display_timezone}
                        hint="Only changes how times look to you — not when anything actually happened."
                    >
                        <Select
                            id="display_timezone"
                            value={data.display_timezone}
                            onChange={(e) => setData('display_timezone', e.target.value)}
                        >
                            <option value="">
                                {`Workspace default — ${timezoneCityLabel(workspaceTimezone)} (${workspaceTimezone})`}
                            </option>
                            {extraZone && (
                                <optgroup label="Current">
                                    <option value={extraZone}>
                                        {`${timezoneCityLabel(extraZone)} — ${extraZone}`}
                                    </option>
                                </optgroup>
                            )}
                            {TIMEZONE_GROUPS.map((group) => (
                                <optgroup key={group.label} label={group.label}>
                                    {group.zones.map((tz) => {
                                        const offset = timezoneOffsetLabel(tz, now);
                                        return (
                                            <option key={tz} value={tz}>
                                                {`${timezoneCityLabel(tz)} — ${tz}${offset ? ` (${offset})` : ''}`}
                                            </option>
                                        );
                                    })}
                                </optgroup>
                            ))}
                        </Select>
                    </Field>

                    <Field
                        label="Time format"
                        error={errors.time_format}
                        hint="Applies everywhere you see a clock time in the app."
                    >
                        <Tabs
                            tabs={FORMAT_TABS}
                            value={data.time_format}
                            onChange={(key) => setData('time_format', key)}
                            aria-label="Time format"
                        />
                    </Field>
                </div>

                {/* Live preview — the whole point of the setting, made visible. */}
                <div className="rounded-lg border border-line bg-surface-2 px-4 py-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-subtle">
                        Preview
                    </p>
                    <p className="mt-1 text-sm text-muted">
                        It is currently{' '}
                        <span className="text-2xl font-semibold tabular-nums text-body align-middle">
                            {formatClock(now, effectiveTimezone, hour12, true)}
                        </span>{' '}
                        in{' '}
                        <span className="font-medium text-body">
                            {timezoneCityLabel(effectiveTimezone)}
                        </span>
                        <span className="text-subtle">
                            {` · ${formatClockDate(now, effectiveTimezone)}`}
                            {timezoneOffsetLabel(effectiveTimezone, now)
                                ? ` · ${timezoneOffsetLabel(effectiveTimezone, now)}`
                                : ''}
                        </span>
                    </p>
                    {device && (
                        <p className="mt-1 text-xs text-subtle">
                            {`Your device is set to ${device}.`}
                            {canUseDevice && (
                                <button
                                    type="button"
                                    onClick={() => setData('display_timezone', device)}
                                    className="ml-1.5 font-medium text-accent underline-offset-2 hover:underline"
                                >
                                    Use my device time zone
                                </button>
                            )}
                        </p>
                    )}
                </div>

                <div className="flex items-center gap-4">
                    <Button type="submit" loading={processing} disabled={processing}>
                        Save
                    </Button>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-muted">Saved.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
