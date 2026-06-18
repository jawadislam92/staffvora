import React, { useEffect, useMemo, useRef, useState } from 'react';

export default function SearchableMultiSelect({
    label,
    options = [],
    selectedValues = [],
    onChange,
    placeholder = 'Select options',
    searchPlaceholder = 'Search...',
    // When true the panel expands in-flow (pushing content down) instead of
    // floating as an absolute overlay. Use this inside a scrollable modal so
    // the modal's own scrollbar handles everything — otherwise the panel's
    // inner scroll stacks against the modal scroll (two scrollbars fighting).
    inline = false,
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const containerRef = useRef(null);

    const selectedSet = useMemo(() => new Set(selectedValues.map(String)), [selectedValues]);
    const selectedOptions = useMemo(
        () => options.filter((option) => selectedSet.has(String(option.value))),
        [options, selectedSet]
    );

    const filteredOptions = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();

        if (!normalizedQuery) {
            return options;
        }

        return options.filter((option) =>
            String(option.label).toLowerCase().includes(normalizedQuery)
        );
    }, [options, query]);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const toggleValue = (value) => {
        const normalizedValue = String(value);
        const next = new Set(selectedSet);

        if (next.has(normalizedValue)) {
            next.delete(normalizedValue);
        } else {
            next.add(normalizedValue);
        }

        onChange(Array.from(next));
    };

    const clearAll = () => {
        onChange([]);
        setQuery('');
    };

    const buttonText = selectedOptions.length
        ? `${selectedOptions.length} selected`
        : placeholder;

    return (
        <div className="relative" ref={containerRef}>
            <label className="mb-1.5 block text-xs font-semibold uppercase text-slate-600">{label}</label>
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className="flex min-h-10 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm text-slate-900 shadow-sm transition hover:border-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
                <span className={selectedOptions.length ? 'font-medium text-slate-900' : 'text-slate-500'}>
                    {buttonText}
                </span>
                <svg
                    className={`h-4 w-4 text-slate-500 transition-transform ${open ? 'rotate-180' : ''}`}
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                </svg>
            </button>

            {open && (
                <div
                    className={
                        inline
                            ? 'relative mt-1.5 w-full rounded-lg border border-slate-200 bg-white'
                            : 'absolute z-40 mt-1.5 w-full rounded-lg border border-slate-200 bg-white shadow-xl'
                    }
                >
                    <div className="border-b border-slate-100 p-2">
                        <input
                            type="text"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={searchPlaceholder}
                            className="w-full rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div className={inline ? 'py-1' : 'max-h-64 overflow-y-auto py-1'}>
                        {filteredOptions.length === 0 ? (
                            <div className="px-3 py-3 text-sm text-slate-500">No options found</div>
                        ) : (
                            filteredOptions.map((option) => {
                                const checked = selectedSet.has(String(option.value));

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => toggleValue(option.value)}
                                        className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm text-slate-800 transition hover:bg-slate-50"
                                    >
                                        <span
                                            className={`flex h-4 w-4 items-center justify-center rounded border ${
                                                checked
                                                    ? 'border-blue-600 bg-blue-600 text-white'
                                                    : 'border-slate-300 bg-white'
                                            }`}
                                        >
                                            {checked && (
                                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fillRule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.25 7.25a1 1 0 01-1.42 0L3.296 9.22a1 1 0 111.414-1.414l4.034 4.034 6.543-6.543a1 1 0 011.417-.006z" clipRule="evenodd" />
                                                </svg>
                                            )}
                                        </span>
                                        <span className="truncate">{option.label}</span>
                                    </button>
                                );
                            })
                        )}
                    </div>

                    {selectedOptions.length > 0 && (
                        <div className="border-t border-slate-100 p-2">
                            <div className="mb-2 flex flex-wrap gap-1.5">
                                {selectedOptions.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => toggleValue(option.value)}
                                        className="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100"
                                        title={`Remove ${option.label}`}
                                    >
                                        <span className="max-w-36 truncate">{option.label}</span>
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                ))}
                            </div>
                            <button
                                type="button"
                                onClick={clearAll}
                                className="w-full rounded-md px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
                            >
                                Clear {label}
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
