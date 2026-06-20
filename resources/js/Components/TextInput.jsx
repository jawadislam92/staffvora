import { forwardRef, useEffect, useRef } from 'react';

export default forwardRef(function TextInput({ type = 'text', className = '', isFocused = false, ...props }, ref) {
    const input = ref ? ref : useRef();

    useEffect(() => {
        if (isFocused) {
            input.current.focus();
        }
    }, []);

    return (
        <input
            {...props}
            type={type}
            className={
                // No explicit bg/text: native form controls are themed in
                // app.css (@layer base) so this input follows the active theme on
                // both the dark Profile page and the auth pages.
                'border-line focus:border-accent focus:ring-accent rounded-md shadow-sm ' +
                className
            }
            ref={input}
        />
    );
});
