import { createRouter, createWebHistory } from 'vue-router';
import { whenSignedOut } from './api.js';
import { can, loadSession, session } from './session.js';

const router = createRouter({
    history: createWebHistory('/console'),
    scrollBehavior: () => ({ top: 0 }),
    routes: [
        { path: '/login', component: () => import('./pages/Login.vue'), meta: { public: true, title: 'Sign in' } },
        { path: '/register', component: () => import('./pages/Register.vue'), meta: { public: true, title: 'Create an account' } },
        { path: '/invite/:token', component: () => import('./pages/Invite.vue'), meta: { public: true, title: 'Join' } },
        { path: '/', component: () => import('./pages/Overview.vue'), meta: { title: 'Overview' } },
        { path: '/payments', component: () => import('./pages/Payments.vue'), meta: { title: 'Transactions' } },
        { path: '/payments/:id', component: () => import('./pages/PaymentDetail.vue'), meta: { title: 'Transaction' } },
        { path: '/transactions', redirect: '/payments' },
        { path: '/companies', component: () => import('./pages/Companies.vue'), meta: { title: 'Companies' } },
        { path: '/companies/:id', component: () => import('./pages/CompanyDetail.vue'), meta: { title: 'Company' } },
        { path: '/charges', component: () => import('./pages/Charges.vue'), meta: { title: 'Charges', needs: 'charges.view' } },
        { path: '/api-log', component: () => import('./pages/ApiLog.vue'), meta: { title: 'API log' } },
        { path: '/try', component: () => import('./pages/TryIt.vue'), meta: { title: 'Try it' } },
        { path: '/team', component: () => import('./pages/TeamPage.vue'), meta: { title: 'Team', needs: 'team.view' } },
        { path: '/activity', component: () => import('./pages/Activity.vue'), meta: { title: 'Activity log', needs: 'activity.view' } },
        { path: '/:rest(.*)*', redirect: '/' },
    ],
});

router.beforeEach(async (to) => {
    if (!session.ready) await loadSession();

    if (to.meta.public) {
        return session.user && ['/login', '/register'].includes(to.path) ? '/' : true;
    }
    if (!session.user) {
        return { path: '/login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} };
    }
    if (to.meta.needs && !can(to.meta.needs)) return '/';
    return true;
});

router.afterEach((to) => {
    document.title = `${to.meta.title || 'Console'} · BlessedPay`;
});

// A request came back "not signed in" (session expired, or account suspended).
whenSignedOut(() => {
    session.user = null;
    if (!router.currentRoute.value.meta.public) router.push('/login');
});

export default router;
