import React from 'react';

const TimeDisplay = ({ timestamp, format = 'full' }) => {
    const date = new Date(timestamp);
    
    const formatOptions = {
        full: {
            timeZone: 'Asia/Karachi',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        },
        time: {
            timeZone: 'Asia/Karachi',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        },
        date: {
            timeZone: 'Asia/Karachi',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        }
    };

    return (
        <span className="pkt-time">
            {date.toLocaleString('en-US', formatOptions[format])}
        </span>
    );
};

export default TimeDisplay;