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

/** To the second: the audit trail needs to show what happened inside one minute. */
export function exact(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

/** A length of time for people: 640 ms, 4.2 s, 3 min 5 s. */
export function span(ms) {
    if (ms == null) return '';
    if (ms < 1000) return `${Math.round(ms)} ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)} s`;
    return `${Math.floor(ms / 60000)} min ${Math.round((ms % 60000) / 1000)} s`;
}

export const METHODS = { card: 'Card', ecocash: 'EcoCash', bank: 'Bank account' };

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
