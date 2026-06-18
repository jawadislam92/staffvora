import React from 'react';
import clsx from 'clsx';

interface TabsContextValue {
  value: string;
  onValueChange: (value: string) => void;
}

const TabsContext = React.createContext<TabsContextValue | null>(null);

export interface TabsProps {
  value: string;
  onValueChange: (value: string) => void;
  children: React.ReactNode;
  className?: string;
}

export function Tabs({ value, onValueChange, children, className }: TabsProps) {
  const ctx = React.useMemo(() => ({ value, onValueChange }), [value, onValueChange]);
  return (
    <TabsContext.Provider value={ctx}>
      <div className={clsx('w-full', className)}>{children}</div>
    </TabsContext.Provider>
  );
}

export interface TabsListProps {
  children: React.ReactNode;
  className?: string;
}

export function TabsList({ children, className }: TabsListProps) {
  return (
    <div className={clsx(
      'inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-slate-100 to-slate-50 p-1.5 text-slate-600 shadow-inner border border-slate-200/60',
      className
    )}>
      {children}
    </div>
  );
}

export interface TabsTriggerProps {
  value: string;
  children: React.ReactNode;
  className?: string;
}

export function TabsTrigger({ value, children, className }: TabsTriggerProps) {
  const ctx = React.useContext(TabsContext);
  const isActive = ctx?.value === value;
  
  return (
    <button
      type="button"
      onClick={() => ctx?.onValueChange?.(value)}
      className={clsx(
        'inline-flex items-center justify-center whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 relative overflow-hidden',
        isActive 
          ? 'bg-white text-slate-900 shadow-md shadow-slate-200/60 border border-slate-200/80 font-semibold' 
          : 'text-slate-600 hover:text-slate-900 hover:bg-white/60',
        className
      )}
    >
      {children}
      {isActive && (
        <div className="absolute inset-x-0 bottom-0 h-0.5 bg-gradient-to-r from-blue-500 to-indigo-500" />
      )}
    </button>
  );
}

export interface TabsContentProps {
  value: string;
  children: React.ReactNode;
  className?: string;
}

export function TabsContent({ value, children, className }: TabsContentProps) {
  const ctx = React.useContext(TabsContext);
  if (ctx && ctx.value !== value) return null;
  
  return (
    <div className={clsx(
      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 animate-in fade-in-50 duration-200', 
      className
    )}>
      {children}
    </div>
  );
}