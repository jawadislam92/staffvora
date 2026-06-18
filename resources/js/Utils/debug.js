/**
 * Debug Mode Configuration
 * 
 * Set to true to enable console logging during development.
 * Set to false for production to disable all debug logs.
 * 
 * Note: In production builds, Vite/Terser will automatically
 * strip console.log statements regardless of this flag.
 */
export const DEBUG_MODE = import.meta.env.MODE === 'development';

/**
 * Conditional console logger
 * Only logs when DEBUG_MODE is enabled
 */
export const debugLog = (...args) => {
    if (DEBUG_MODE) {
        console.log(...args);
    }
};

/**
 * Always log errors regardless of debug mode
 */
export const errorLog = (...args) => {
    console.error(...args);
};

/**
 * Always log warnings regardless of debug mode
 */
export const warnLog = (...args) => {
    console.warn(...args);
};
