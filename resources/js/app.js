// The landing page is plain server-rendered HTML and needs no JavaScript.
// The console and the checkout are Vue apps, each loaded only on its own page.
if (document.getElementById('console')) {
    import('./console/main.js');
}
if (document.getElementById('checkout')) {
    import('./checkout/main.js');
}
