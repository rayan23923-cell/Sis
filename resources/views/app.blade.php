<!DOCTYPE html>
@php($locale = app()->getLocale())
@php($locale !== 'ar' && $page['props'] = \App\Support\Translations::deep($page['props'], $locale))
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#ffffff">
        <title inertia>{{ config('app.name') }}</title>
        <script>
            window.__i18n = @json($locale === 'ar' ? new stdClass : \App\Support\Translations::for($locale));
            window.__ = function (key, params) {
                var s = Object.prototype.hasOwnProperty.call(window.__i18n, key) ? window.__i18n[key] : key;
                (params || []).forEach(function (v, i) { s = s.split(':' + i).join(v == null ? '' : v); });
                return s;
            };
        </script>
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-800">
        @inertia
    </body>
</html>
