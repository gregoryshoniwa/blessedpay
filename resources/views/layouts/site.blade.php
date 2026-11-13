<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BlessedPay')</title>
    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif
    @hasSection('noindex')
        <meta name="robots" content="noindex, nofollow">
    @endif
    <meta name="theme-color" content="#000000">
    <link rel="icon" href="/favicon.ico">
    @yield('head')
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="@yield('body', 'bg-black text-white') antialiased">
    @yield('content')
</body>
</html>
