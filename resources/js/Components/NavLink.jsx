import { Link } from '@inertiajs/react';

export default function NavLink({ active = false, className = '', children, ...props }) {
    return (
        <Link
            {...props}
            className={
                'inline-flex items-center px-3 py-2 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none rounded-t-lg ' +
                (active
                    ? 'border-blue-600 text-blue-600 bg-blue-100/60 shadow-sm' 
                    : 'border-transparent text-slate-600 hover:text-slate-800 hover:bg-slate-100 hover:border-slate-300 focus:text-slate-800 focus:bg-slate-100 ') +
                className
            }
        >
            {children}
        </Link>
    );
}
