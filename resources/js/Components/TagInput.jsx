import React, { useState } from 'react';

export default function TagInput({ tags = [], onChange, placeholder = 'Add tags...' }) {
    const [inputValue, setInputValue] = useState('');

    const addTag = (tagText) => {
        const trimmedTag = tagText.trim();
        if (trimmedTag && !tags.includes(trimmedTag)) {
            onChange([...tags, trimmedTag]);
        }
        setInputValue('');
    };

    const removeTag = (indexToRemove) => {
        onChange(tags.filter((_, index) => index !== indexToRemove));
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            addTag(inputValue);
        } else if (event.key === 'Backspace' && !inputValue && tags.length > 0) {
            removeTag(tags.length - 1);
        }
    };

    const handleInputBlur = () => {
        if (inputValue.trim()) {
            addTag(inputValue);
        }
    };

    return (
        <div className="w-full">
            <div className="mb-3 flex flex-wrap gap-2">
                {tags.map((tag, index) => (
                    <span
                        key={`${tag}-${index}`}
                        className="group inline-flex items-center rounded-md border border-emerald-300 bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-800 transition-colors hover:bg-emerald-100"
                    >
                        {tag}
                        <button
                            type="button"
                            onClick={() => removeTag(index)}
                            className="ml-2 rounded-sm text-emerald-700 transition-colors hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            aria-label={`Remove ${tag}`}
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </span>
                ))}
            </div>
            <input
                type="text"
                value={inputValue}
                onChange={(event) => setInputValue(event.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={handleInputBlur}
                placeholder={placeholder}
                className="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 transition-all placeholder-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500"
            />
            <p className="mt-2 text-xs text-slate-500">
                Press Enter or comma to add a tag. Click x to remove tags.
            </p>
        </div>
    );
}
