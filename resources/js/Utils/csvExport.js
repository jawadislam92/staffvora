export function exportRowsToCsv(rows, filename) {
    const list = Array.isArray(rows) ? rows : [];

    if (list.length === 0) {
        const emptyBlob = new Blob([''], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(emptyBlob, filename);
        return;
    }

    const headers = Object.keys(list[0]);
    const lines = [
        headers.map(escapeCsvValue).join(','),
        ...list.map((row) => headers.map((header) => escapeCsvValue(row[header])).join(',')),
    ];

    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    downloadBlob(blob, filename);
}

function escapeCsvValue(value) {
    const normalized = value === null || value === undefined ? '' : String(value);
    return `"${normalized.replace(/"/g, '""')}"`;
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}
