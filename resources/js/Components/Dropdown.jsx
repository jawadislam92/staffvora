import { useState, createContext, useContext, Fragment, useEffect, useRef } from 'react';
import { Link } from '@inertiajs/react';
import { Transition } from '@headlessui/react';

const DropDownContext = createContext();

const Dropdown = ({ children }) => {
    const [open, setOpen] = useState(false);
    const dropdownRef = useRef(null);

    const toggleOpen = () => {
        setOpen((previousState) => !previousState);
    };

    // Handle click outside to close dropdown
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        if (open) {
            document.addEventListener('mousedown', handleClickOutside);
            document.addEventListener('touchstart', handleClickOutside);
        }

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('touchstart', handleClickOutside);
        };
    }, [open]);

    return (
        <DropDownContext.Provider value={{ open, setOpen, toggleOpen }}>
            <div ref={dropdownRef} className="relative">{children}</div>
        </DropDownContext.Provider>
    );
};

const Trigger = ({ children }) => {
    const { open, setOpen, toggleOpen } = useContext(DropDownContext);

    return (
        <div onClick={toggleOpen}>{children}</div>
    );
};

const Content = ({ align = 'right', width = '48', contentClasses = 'py-1 bg-white', direction = 'down', children }) => {
    const { open, setOpen } = useContext(DropDownContext);

    let alignmentClasses = 'origin-top';
    let positionClasses = 'mt-2'; // Default: dropdown appears below

    // Handle direction
    if (direction === 'up') {
        alignmentClasses = 'origin-bottom';
        positionClasses = 'bottom-full mb-2'; // Appear above the trigger
    } else if (direction === 'side' || direction === 'right') {
        alignmentClasses = 'origin-left';
        positionClasses = 'left-full ml-2 bottom-0'; // Appear to the right side
    }

    if (align === 'left' && direction !== 'side' && direction !== 'right') {
        alignmentClasses = direction === 'up' ? 'ltr:origin-bottom-left rtl:origin-bottom-right start-0' : 'ltr:origin-top-left rtl:origin-top-right start-0';
    } else if (align === 'right' && direction !== 'side' && direction !== 'right') {
        alignmentClasses = direction === 'up' ? 'ltr:origin-bottom-right rtl:origin-bottom-left end-0' : 'ltr:origin-top-right rtl:origin-top-left end-0';
    }

    let widthClasses = '';

    if (width === '48') {
        widthClasses = 'w-48';
    } else if (width === '56') {
        widthClasses = 'w-56';
    }

    return (
        <>
            <Transition
                as={Fragment}
                show={open}
                enter="transition ease-out duration-200"
                enterFrom="opacity-0 scale-95"
                enterTo="opacity-100 scale-100"
                leave="transition ease-in duration-75"
                leaveFrom="opacity-100 scale-100"
                leaveTo="opacity-0 scale-95"
            >
                <div
                    className={`absolute z-[9999] ${positionClasses} rounded-md shadow-lg ${alignmentClasses} ${widthClasses}`}
                >
                    <div className={`rounded-md ring-1 ring-black ring-opacity-5 ` + contentClasses}>{children}</div>
                </div>
            </Transition>
        </>
    );
};

const DropdownLink = ({ className = '', children, ...props }) => {
    return (
        <Link
            {...props}
            className={
                'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out ' +
                className
            }
        >
            {children}
        </Link>
    );
};

Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;

export default Dropdown;
