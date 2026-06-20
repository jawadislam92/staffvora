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
                // Fixed dark text + white field: this component is only used on
                // white-card pages (Profile, password resets), so the value must
                // not inherit the (light) theme text token and type invisibly.
                'border-gray-300 bg-white text-[#111827] placeholder:text-[#9ca3af] focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm ' +
                className
            }
            ref={input}
        />
    );
});
