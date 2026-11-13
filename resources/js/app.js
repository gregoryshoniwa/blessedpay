// The public pages are plain server-rendered HTML and need no JavaScript.
// The console is a Vue app, loaded only on pages that have its mount point.
if (document.getElementById('console')) {
    import('./console/main.js');
}
