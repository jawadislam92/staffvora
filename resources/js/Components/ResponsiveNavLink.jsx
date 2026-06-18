import { Link } from '@inertiajs/react';

export default function ResponsiveNavLink({ active = false, className = '', children, ...props }) {
    return (
        <Link
            {...props}
            className={`w-full flex items-start ps-3 pe-4 py-2 border-l-4 rounded-r-lg ${
                active
                    ? 'border-blue-600 text-blue-600 bg-blue-100 shadow-sm focus:text-blue-700 focus:bg-blue-150 focus:border-blue-700'
                    : 'border-transparent text-slate-600 hover:text-slate-800 hover:bg-slate-100 hover:border-slate-300 focus:text-slate-800 focus:bg-slate-100 focus:border-slate-300'
            } text-base font-medium focus:outline-none transition duration-150 ease-in-out ${className}`}
        >
            {children}
        </Link>
    );
}
