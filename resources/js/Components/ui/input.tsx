import React from 'react';
import clsx from 'clsx';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  helper?: string;
  icon?: React.ReactNode;
  required?: boolean;
  showCharCount?: boolean;
}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, label, error, helper, icon, type = 'text', required, showCharCount, maxLength, value, ...props }, ref) => {
    const currentLength = typeof value === 'string' ? value.length : 0;
    
    return (
      <div className="space-y-2">
        {label && (
          <label className="text-sm font-medium text-slate-700 block">
            {label}
            {required && <span className="text-red-500 ml-1">*</span>}
          </label>
        )}
        <div className="relative">
          {icon && (
            <div className="absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400">
              {icon}
            </div>
          )}
          <input
            type={type}
            className={clsx(
              'w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 placeholder-slate-400 shadow-sm transition-all duration-200',
              'focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 focus:outline-none',
              'hover:border-slate-300',
              'disabled:bg-slate-50 disabled:text-slate-500 disabled:cursor-not-allowed',
              icon && 'pl-10',
              error && 'border-red-300 focus:border-red-500 focus:ring-red-500/10',
              className
            )}
            maxLength={maxLength}
            value={value}
            ref={ref}
            {...props}
          />
        </div>
        {showCharCount && maxLength && (
          <div className="flex justify-between items-center">
            {helper && !error && (
              <p className="text-xs text-slate-500">{helper}</p>
            )}
            <p className={clsx(
              'text-xs',
              currentLength > maxLength * 0.9 ? 'text-amber-600' : 'text-slate-500',
              currentLength >= maxLength ? 'text-red-600' : ''
            )}>
              {currentLength}/{maxLength}
            </p>
          </div>
        )}
        {(!showCharCount || !maxLength) && helper && !error && (
          <p className="text-xs text-slate-500">{helper}</p>
        )}
        {error && (
          <p className="text-xs text-red-600 flex items-center gap-1">
            <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
            </svg>
            {error}
          </p>
        )}
      </div>
    );
  }
);

Input.displayName = 'Input';