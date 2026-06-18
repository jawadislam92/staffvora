import React from 'react';
import clsx from 'clsx';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'default' | 'outline' | 'ghost' | 'link' | 'primary' | 'secondary' | 'danger';
  size?: 'sm' | 'md' | 'lg';
}

export function Button({ variant = 'default', size = 'md', className, ...props }: ButtonProps) {
  const base = 'inline-flex items-center justify-center rounded-lg font-medium transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none';
  
  const variants: Record<string, string> = {
    default: 'bg-slate-900 text-white shadow-lg hover:bg-slate-800 focus-visible:ring-slate-900 hover:shadow-xl',
    primary: 'bg-slate-900 text-white shadow-lg hover:bg-slate-800 focus-visible:ring-slate-900 hover:shadow-xl',
    secondary: 'bg-white text-slate-900 shadow-md border border-slate-200 hover:bg-slate-50 focus-visible:ring-slate-900 hover:shadow-lg',
    outline: 'border border-slate-300 bg-transparent text-slate-700 hover:bg-slate-50 focus-visible:ring-slate-900 hover:border-slate-400',
    ghost: 'bg-transparent text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-900',
    danger: 'bg-red-600 text-white shadow-lg hover:bg-red-700 focus-visible:ring-red-600 hover:shadow-xl',
    link: 'text-blue-600 underline-offset-4 hover:underline bg-transparent',
  };

  const sizes: Record<string, string> = {
    sm: 'h-8 px-3 text-sm',
    md: 'h-10 px-4 text-sm',
    lg: 'h-12 px-6 text-base',
  };

  return (
    <button className={clsx(base, variants[variant], sizes[size], className)} {...props} />
  );
}

export default Button;
