import React from 'react';
import toast from 'react-hot-toast';
import { CheckCircle, AlertCircle, XCircle, Info, Clock, Coffee, LogIn, LogOut } from 'lucide-react';

/**
 * Modern notification system for time tracking actions
 */

const toastStyle = {
    borderRadius: '12px',
    background: '#ffffff',
    color: '#1f2937',
    border: '1px solid #e5e7eb',
    padding: '16px',
    fontSize: '14px',
    fontWeight: '500',
    boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)',
};

const iconStyle = {
    width: '20px',
    height: '20px',
    marginRight: '8px',
};

/**
 * Show success notification for time tracking actions
 * @param {string} actionType - The type of action performed
 * @param {string} message - Custom message (optional)
 */
export const showTimeActionSuccess = (actionType, message = null) => {
    const actionMessages = {
        'clock_in': {
            message: message || 'Successfully clocked in! Ready to be productive? 💪',
            icon: LogIn,
            color: '#10b981'
        },
        'clock_out': {
            message: message || 'Successfully clocked out! Great work today! 🎉',
            icon: LogOut,
            color: '#ef4444'
        },
        'break_start': {
            message: message || 'Break started! Take your time to recharge ☕',
            icon: Coffee,
            color: '#f59e0b'
        },
        'break_end': {
            message: message || 'Welcome back! Ready to continue? 🔄',
            icon: Clock,
            color: '#3b82f6'
        }
    };

    const config = actionMessages[actionType] || {
        message: message || 'Action completed successfully!',
        icon: CheckCircle,
        color: '#10b981'
    };

    toast.success(config.message, {
        style: {
            ...toastStyle,
            borderColor: config.color,
            borderLeftWidth: '4px',
        },
        icon: React.createElement(config.icon, {
            style: { ...iconStyle, color: config.color }
        }),
        duration: 4000,
    });
};

/**
 * Show error notification
 * @param {string} message - Error message
 */
export const showError = (message) => {
    toast.error(message, {
        style: {
            ...toastStyle,
            borderColor: '#ef4444',
            borderLeftWidth: '4px',
        },
        icon: React.createElement(XCircle, {
            style: { ...iconStyle, color: '#ef4444' }
        }),
        duration: 5000,
    });
};

/**
 * Show info notification
 * @param {string} message - Info message
 */
export const showInfo = (message) => {
    toast(message, {
        style: {
            ...toastStyle,
            borderColor: '#3b82f6',
            borderLeftWidth: '4px',
        },
        icon: React.createElement(Info, {
            style: { ...iconStyle, color: '#3b82f6' }
        }),
        duration: 3000,
    });
};

/**
 * Show warning notification
 * @param {string} message - Warning message
 */
export const showWarning = (message) => {
    toast(message, {
        style: {
            ...toastStyle,
            borderColor: '#f59e0b',
            borderLeftWidth: '4px',
        },
        icon: React.createElement(AlertCircle, {
            style: { ...iconStyle, color: '#f59e0b' }
        }),
        duration: 4000,
    });
};

/**
 * Show action blocked notification
 * @param {string} actionType - The blocked action type
 * @param {string} reason - Reason why it's blocked
 */
export const showActionBlocked = (actionType, reason) => {
    const actionLabels = {
        'clock_in': 'Clock In',
        'clock_out': 'Clock Out',
        'break_start': 'Start Break',
        'break_end': 'End Break'
    };

    const message = `${actionLabels[actionType] || actionType} is not available. ${reason}`;
    
    toast(message, {
        style: {
            ...toastStyle,
            borderColor: '#6b7280',
            borderLeftWidth: '4px',
        },
        icon: React.createElement(AlertCircle, {
            style: { ...iconStyle, color: '#6b7280' }
        }),
        duration: 3000,
    });
};

/**
 * Show loading notification for async actions
 * @param {string} message - Loading message
 * @returns {string} Toast ID for dismissing later
 */
export const showLoading = (message = 'Processing...') => {
    return toast.loading(message, {
        style: {
            ...toastStyle,
            borderColor: '#6b7280',
            borderLeftWidth: '4px',
        },
    });
};

/**
 * Show CSV export success notification
 */
export const showExportSuccess = () => {
    toast.success('📊 Time entries exported successfully! Check your downloads folder.', {
        style: {
            ...toastStyle,
            borderColor: '#10b981',
            borderLeftWidth: '4px',
        },
        icon: React.createElement(CheckCircle, {
            style: { ...iconStyle, color: '#10b981' }
        }),
        duration: 5000,
    });
};

/**
 * Show reminder notification based on work patterns
 * @param {string} type - Type of reminder ('break', 'clockout', etc.)
 */
export const showWorkReminder = (type) => {
    const reminders = {
        'break': {
            message: '☕ Time for a break! You\'ve been working for a while.',
            color: '#f59e0b'
        },
        'clockout': {
            message: '🏁 Don\'t forget to clock out when you\'re done for the day!',
            color: '#ef4444'
        },
        'clockin': {
            message: '⏰ Ready to start your day? Don\'t forget to clock in!',
            color: '#10b981'
        }
    };

    const config = reminders[type] || reminders['clockin'];

    toast(config.message, {
        style: {
            ...toastStyle,
            borderColor: config.color,
            borderLeftWidth: '4px',
        },
        icon: React.createElement(Clock, {
            style: { ...iconStyle, color: config.color }
        }),
        duration: 6000,
    });
};