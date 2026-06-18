const fs = require('fs');

// Read the AuthenticatedLayout file
const filePath = 'resources/js/Layouts/AuthenticatedLayout.jsx';
let content = fs.readFileSync(filePath, 'utf8');

// Define the patterns to fix with proper spacing in SVG arc flags
const fixes = [
    // Fix 515.356-1.857 -> 5 1 5.356-1.857
    { from: 'a3 3 0 515.356-1.857', to: 'a3 3 0 5 1 5.356-1.857' },
    // Fix 616 0 -> 6 1 6 0
    { from: 'a3 3 0 616 0', to: 'a3 3 0 6 1 6 0' },
    // Fix 713-3 -> 7 1 3-3
    { from: 'a3 3 0 713-3', to: 'a3 3 0 7 1 3-3' },
    // Fix 713 3 -> 7 1 3 3
    { from: 'a3 3 0 713 3', to: 'a3 3 0 7 1 3 3' },
    // Fix 712-2 -> 7 1 2-2
    { from: 'a2 2 0 712-2', to: 'a2 2 0 7 1 2-2' },
    // Fix 712 2 -> 7 1 2 2
    { from: 'a2 2 0 712 2', to: 'a2 2 0 7 1 2 2' },
    // Fix 71-2 2 -> 7 1-2 2
    { from: 'a2 2 0 71-2 2', to: 'a2 2 0 7 1-2 2' },
    // Fix 71-2-2 -> 7 1-2-2
    { from: 'a2 2 0 71-2-2', to: 'a2 2 0 7 1-2-2' },
    // Fix 818 0 -> 8 1 8 0
    { from: 'a4 4 0 818 0', to: 'a4 4 0 8 1 8 0' },
    // Fix 71-3 3 -> 7 1-3 3
    { from: 'a3 3 0 71-3 3', to: 'a3 3 0 7 1-3 3' },
    // Fix 71-3-3 -> 7 1-3-3
    { from: 'a3 3 0 71-3-3', to: 'a3 3 0 7 1-3-3' }
];

console.log('Starting SVG path fixes...');

// Apply all fixes
fixes.forEach(fix => {
    const beforeCount = (content.match(new RegExp(fix.from, 'g')) || []).length;
    content = content.replace(new RegExp(fix.from, 'g'), fix.to);
    const afterCount = (content.match(new RegExp(fix.to, 'g')) || []).length;
    if (beforeCount > 0) {
        console.log(`Fixed ${beforeCount} instances of "${fix.from}" -> "${fix.to}"`);
    }
});

// Write the fixed content back to file
fs.writeFileSync(filePath, content, 'utf8');

console.log('SVG paths fixed successfully!');