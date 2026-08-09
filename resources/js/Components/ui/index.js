// Staffvora shared UI kit — one import surface for every page.
//   import { Button, Card, StatCard, Table, Th, Td, Badge, Field, Input } from '@/Components/ui'
// All components are theme-aware (dark / light / black) via the semantic tokens
// in resources/css/app.css. Electric-blue accent = bg-accent / text-accent.
export { default as Spinner } from './Spinner';
export { default as Button } from './button';
export { default as Card, CardHeader } from './Card';
export { default as Badge } from './Badge';
export { default as StatCard, DeltaChip } from './StatCard';
export { default as KpiCard } from './KpiCard';
export { default as Table, THead, TBody, Tr, Th, Td } from './Table';
export { default as Field, Input, Select, Textarea, Checkbox, Toggle } from './Form';
export { default as EmptyState, DefaultInboxIcon } from './EmptyState';
export { default as Skeleton, SkeletonText, SkeletonCard } from './Skeleton';
export { default as Tabs, Segmented } from './Tabs';
// The "shown, not hidden" upgrade pattern: a capability the workspace has not
// bought stays VISIBLE, greyed, with a lock and an "Upgrade to unlock" CTA.
// PRESENTATION ONLY — the server still decides via `entitlement:<key>`.
export {
    default as LockedFeature,
    Locked,
    LockBadge,
    LockGlyph,
    UpgradeCallout,
} from './Locked';
