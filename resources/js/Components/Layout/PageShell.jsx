export default function PageShell({ children, width = 'max-w-[1600px]', className = '' }) {
    return (
        <div className={`mx-auto ${width} space-y-6 px-4 py-6 sm:px-6 lg:px-8 ${className}`}>
            {children}
        </div>
    );
}
