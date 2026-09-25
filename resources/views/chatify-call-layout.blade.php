<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), config('chatify.rtl_locales', ['ar']), true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('chatify::chatify.ui.app_name', ['name' => config('chatify.name', 'Chatify Messenger')]) }}</title>
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/calls.css') }}">
    @vite('resources/js/app.js')
    <script src="https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js" defer></script>
    <script src="{{ asset('js/calls.js') }}" defer></script>
</head>
<body data-call-user-id="{{ auth()->id() }}" class="chatify:antialiased chatify:m-0 chatify:overflow-hidden" style="overscroll-behavior: none; touch-action: manipulation;">
    @yield('content')
</body>
</html>
