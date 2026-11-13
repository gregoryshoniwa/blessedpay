// One small fetch wrapper for the console's own endpoints (/console-api).
// Session cookie + Laravel's CSRF cookie; JSON in and out.

function csrf() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export class ApiError extends Error {
    constructor(status, message, errors) {
        super(message);
        this.status = status;
        this.errors = errors || {};
    }
}

let onSignedOut = () => {};
export function whenSignedOut(fn) {
    onSignedOut = fn;
}

export async function api(method, path, body) {
    let response;
    try {
        response = await fetch('/console-api' + path, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrf(),
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
    } catch {
        throw new ApiError(0, 'Could not reach BlessedPay. Check your connection and try again.');
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        if (response.status === 401 && !['/login', '/register', '/me'].includes(path)) onSignedOut();
        if (response.status === 419) {
            throw new ApiError(419, 'This page was open too long. Refresh it and try again.');
        }
        throw new ApiError(response.status, data.message || 'Something went wrong. Try again.', data.errors);
    }

    return data;
}

export const get = (path, params) => {
    const query = params
        ? '?' + new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString()
        : '';
    return api('GET', path + query);
};
export const post = (path, body = {}) => api('POST', path, body);
export const put = (path, body = {}) => api('PUT', path, body);
export const del = (path) => api('DELETE', path);
