/**
 * Time utility functions for the dashboard
 */

/**
 * Time zones offered by the per-viewer display preference picker, grouped by
 * region. These are only DISPLAY zones — they never change how a timestamp is
 * stored, nor which attendance day it buckets into (that stays the workspace's
 * business timezone on the server).
 */
export const TIMEZONE_GROUPS = [
    {
        label: 'Asia & Middle East',
        zones: [
            'Asia/Karachi', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Kathmandu',
            'Asia/Dubai', 'Asia/Riyadh', 'Asia/Qatar', 'Asia/Tehran',
            'Asia/Jakarta', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Hong_Kong',
            'Asia/Shanghai', 'Asia/Tokyo', 'Asia/Seoul', 'Asia/Manila',
        ],
    },
    {
        label: 'Europe & Africa',
        zones: [
            'Europe/London', 'Europe/Dublin', 'Europe/Lisbon', 'Europe/Madrid',
            'Europe/Paris', 'Europe/Berlin', 'Europe/Amsterdam', 'Europe/Rome',
            'Europe/Warsaw', 'Europe/Kyiv', 'Europe/Istanbul', 'Europe/Moscow',
            'Africa/Lagos', 'Africa/Cairo', 'Africa/Nairobi', 'Africa/Johannesburg',
        ],
    },
    {
        label: 'Americas',
        zones: [
            'America/Sao_Paulo', 'America/Argentina/Buenos_Aires', 'America/Bogota',
            'America/New_York', 'America/Toronto', 'America/Chicago',
            'America/Mexico_City', 'America/Denver', 'America/Phoenix',
            'America/Los_Angeles', 'America/Vancouver', 'America/Anchorage',
            'Pacific/Honolulu',
        ],
    },
    {
        label: 'Oceania',
        zones: [
            'Australia/Perth', 'Australia/Adelaide', 'Australia/Brisbane',
            'Australia/Sydney', 'Australia/Melbourne', 'Pacific/Auckland',
        ],
    },
    {
        label: 'Other',
        zones: ['UTC'],
    },
];

/** Every zone in TIMEZONE_GROUPS, flattened — handy for "is this one we list?". */
export const TIMEZONE_OPTIONS = TIMEZONE_GROUPS.flatMap((group) => group.zones);

/**
 * Human label for an IANA zone: "Asia/Karachi" -> "Karachi",
 * "America/Argentina/Buenos_Aires" -> "Buenos Aires".
 * @param {string} tz
 * @returns {string}
 */
export const timezoneCityLabel = (tz) => {
    if (!tz) return '';
    return String(tz).split('/').pop().replace(/_/g, ' ');
};

/**
 * Current UTC offset label for a zone, e.g. "UTC+5" / "UTC-04:00".
 * DST-aware because it is resolved against a real instant. Returns '' when the
 * runtime can't resolve the zone, so callers can just append it.
 * @param {string} tz
 * @param {Date|string|number} [at] - Instant to resolve the offset at.
 * @returns {string}
 */
export const timezoneOffsetLabel = (tz, at = new Date()) => {
    if (!tz) return '';
    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: tz,
            timeZoneName: 'shortOffset',
        }).formatToParts(new Date(at));
        const name = parts.find((p) => p.type === 'timeZoneName')?.value || '';
        if (!name) return '';
        return name === 'GMT' ? 'UTC+0' : name.replace('GMT', 'UTC');
    } catch {
        return '';
    }
};

/** The viewer's own device time zone, or '' when it can't be resolved. */
export const deviceTimezone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch {
        return '';
    }
};

/**
 * Wall-clock time for an instant in a given zone, honouring the 12/24h choice.
 * @param {Date|string|number} at
 * @param {string} tz - IANA zone.
 * @param {boolean} [hour12] - true = 12-hour (3:45 PM), false = 24-hour (15:45).
 * @param {boolean} [withSeconds]
 * @returns {string}
 */
export const formatClock = (at, tz, hour12 = true, withSeconds = false) => {
    try {
        return new Date(at).toLocaleTimeString('en-US', {
            timeZone: tz || undefined,
            hour: hour12 ? 'numeric' : '2-digit',
            minute: '2-digit',
            ...(withSeconds ? { second: '2-digit' } : {}),
            hour12,
        });
    } catch {
        return '';
    }
};

/**
 * Calendar date for an instant in a given zone, e.g. "Wed, Jul 29".
 * Useful next to formatClock so a viewer can see when their zone is a day off.
 * @param {Date|string|number} at
 * @param {string} tz
 * @returns {string}
 */
export const formatClockDate = (at, tz) => {
    try {
        return new Date(at).toLocaleDateString('en-US', {
            timeZone: tz || undefined,
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return '';
    }
};

/**
 * Format hours and minutes from decimal hours
 * @param {number} hours - Decimal hours (e.g., 2.5 for 2 hours 30 minutes)
 * @returns {string} Formatted time string
 */
export const formatHours = (hours) => {
    const safeHours = Math.max(0, Number(hours) || 0);

    if (safeHours === 0) return '0m';
    
    const h = Math.floor(safeHours);
    const m = Math.round((safeHours - h) * 60);
    
    if (h === 0) return `${m}m`;
    if (m === 0) return `${h}h`;
    return `${h}h ${m}m`;
};

/**
 * Calculate time difference between two timestamps
 * @param {Date|string} start - Start time
 * @param {Date|string} end - End time (defaults to now)
 * @returns {number} Hours difference
 */
export const getTimeDifference = (start, end = new Date()) => {
    const startTime = new Date(start);
    const endTime = new Date(end);
    return (endTime - startTime) / (1000 * 60 * 60); // Convert to hours
};

/**
 * Get the current time formatted for Pakistan timezone
 * @param {boolean} includeSeconds - Whether to include seconds
 * @returns {string} Formatted time string
 */
export const getCurrentTime = (includeSeconds = false) => {
    return new Date().toLocaleTimeString('en-US', {
        timeZone: 'Asia/Karachi',
        hour12: false,
        hour: '2-digit',
        minute: '2-digit',
        ...(includeSeconds && { second: '2-digit' })
    });
};

/**
 * Get the current date formatted for Pakistan timezone
 * @returns {string} Formatted date string
 */
export const getCurrentDate = () => {
    return new Date().toLocaleDateString('en-US', {
        timeZone: 'Asia/Karachi',
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

/**
 * Calculate productivity metrics from time entries
 * @param {Array} entries - Array of time entries
 * @returns {Object} Metrics object
 */
export const calculateProductivityMetrics = (entries) => {
    if (!entries || entries.length === 0) {
        return {
            totalWorkTime: 0,
            totalBreakTime: 0,
            sessionsCount: 0,
            averageSessionLength: 0,
            productivityScore: 0
        };
    }

    let totalWorkMinutes = 0;
    let totalBreakMinutes = 0;
    let sessions = 0;
    let currentSessionStart = null;
    let currentBreakStart = null;
    
    // Sort entries by time (oldest first)
    const sortedEntries = [...entries].sort((a, b) => 
        new Date(a.action_timestamp) - new Date(b.action_timestamp)
    );

    for (let entry of sortedEntries) {
        const entryTime = new Date(entry.action_timestamp);
        
        switch (entry.action_type) {
            case 'clock_in':
                currentSessionStart = entryTime;
                sessions++;
                break;
            case 'clock_out':
                if (currentSessionStart) {
                    totalWorkMinutes += (entryTime - currentSessionStart) / (1000 * 60);
                    currentSessionStart = null;
                }
                break;
            case 'break_start':
                currentBreakStart = entryTime;
                break;
            case 'break_end':
                if (currentBreakStart) {
                    totalBreakMinutes += (entryTime - currentBreakStart) / (1000 * 60);
                    currentBreakStart = null;
                }
                break;
        }
    }

    // If still clocked in, add time until now
    if (currentSessionStart) {
        totalWorkMinutes += (new Date() - currentSessionStart) / (1000 * 60);
    }

    // If on break, add break time until now
    if (currentBreakStart) {
        totalBreakMinutes += (new Date() - currentBreakStart) / (1000 * 60);
    }

    const effectiveWorkTime = Math.max(0, totalWorkMinutes - totalBreakMinutes);
    const averageSessionLength = sessions > 0 ? effectiveWorkTime / sessions : 0;
    
    // Simple productivity score based on work vs break ratio
    const productivityScore = totalWorkMinutes > 0 
        ? Math.min(100, Math.round((effectiveWorkTime / totalWorkMinutes) * 100))
        : 0;

    return {
        totalWorkTime: effectiveWorkTime / 60, // Convert to hours
        totalBreakTime: totalBreakMinutes / 60, // Convert to hours
        sessionsCount: sessions,
        averageSessionLength: averageSessionLength / 60, // Convert to hours
        productivityScore
    };
};

/**
 * Get appropriate greeting based on time of day
 * @returns {string} Greeting message
 */
export const getTimeBasedGreeting = () => {
    const hour = new Date().getHours();
    
    if (hour < 5) return 'Working late night? 🌙';
    if (hour < 12) return 'Good morning! ☀️';
    if (hour < 17) return 'Good afternoon! 🌤️';
    if (hour < 20) return 'Good evening! 🌅';
    return 'Good evening! 🌙';
};

/**
 * Get the next recommended action based on current state
 * @param {string} lastAction - The last action performed
 * @param {Date} lastActionTime - When the last action was performed
 * @returns {string} Next recommended action
 */
export const getNextRecommendedAction = (lastAction, lastActionTime) => {
    if (!lastAction) return 'clock_in';
    
    const timeSinceLastAction = new Date() - new Date(lastActionTime);
    const hoursSinceLastAction = timeSinceLastAction / (1000 * 60 * 60);
    
    switch (lastAction) {
        case 'clock_out':
            return 'clock_in';
        case 'break_end':
            // If it's been more than 4 hours since break ended, suggest another break
            return hoursSinceLastAction > 4 ? 'break_start' : 'clock_out';
        case 'clock_in':
            // If it's been more than 2 hours since clocking in, suggest a break
            return hoursSinceLastAction > 2 ? 'break_start' : 'clock_out';
        case 'break_start':
            return 'break_end';
        default:
            return 'clock_in';
    }
};
