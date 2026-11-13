export function money(amount, currency) {
    const n = Number(amount);
    const text = Number.isFinite(n)
        ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : String(amount);
    return `${currency} ${text}`;
}

export function when(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function ago(iso) {
    if (!iso) return 'Never';
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 90) return 'Just now';
    const steps = [
        [3600, 60, 'minute'],
        [86400, 3600, 'hour'],
        [86400 * 30, 86400, 'day'],
    ];
    for (const [limit, size, unit] of steps) {
        if (seconds < limit) {
            const n = Math.round(seconds / size);
            return `${n} ${unit}${n === 1 ? '' : 's'} ago`;
        }
    }
    return when(iso);
}
