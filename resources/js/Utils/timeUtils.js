/**
 * Time utility functions for the dashboard
 */

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
