import { reactive } from 'vue';
import { get, post } from './api.js';

function savedMode() {
    try {
        return localStorage.getItem('bp-mode') === 'live' ? 'live' : 'test';
    } catch {
        return 'test';
    }
}

// Who is signed in, what they may do, and whether they are looking at test or
// live payments. `mode` only filters what is shown — a key decides a payment's mode.
export const session = reactive({
    ready: false,
    user: null,
    abilities: [],
    roles: { staff: {}, company: {} },
    mode: savedMode(),
});

function fill(data) {
    session.user = data.user;
    session.abilities = data.abilities;
    session.roles = data.roles;
}

export async function loadSession() {
    try {
        fill(await get('/me'));
    } catch {
        session.user = null;
    }
    session.ready = true;
}

export async function signIn(email, password) {
    fill(await post('/login', { email, password }));
}

export function adopt(data) {
    fill(data);
    session.ready = true;
}

export async function signOut() {
    await post('/logout').catch(() => {});
    session.user = null;
    session.abilities = [];
}

export const can = (ability) => session.abilities.includes(ability);

export function setMode(mode) {
    session.mode = mode;
    try {
        localStorage.setItem('bp-mode', mode);
    } catch {
        /* private window: the choice just won't be remembered */
    }
}
