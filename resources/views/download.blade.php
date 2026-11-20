<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تحميل التطبيقات - {{ config('sis.university_name') }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        :root { color-scheme: light dark; --bg: #f4f4f5; --card: #fff; --text: #1b1e21; --muted: #707579; --border: #e1e2e4; --accent: #229ed9; }
        @media (prefers-color-scheme: dark) { :root { --bg: #17212b; --card: #232e3c; --text: #f5f5f5; --muted: #7d8b99; --border: #101921; --accent: #2aabee; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font-family: Tahoma, "Segoe UI", sans-serif; padding: 32px 16px; }
        main { max-width: 640px; margin: 0 auto; }
        .logo { width: 56px; height: 56px; border-radius: 14px; display: block; margin-bottom: 14px; }
        h1 { font-size: 1.4rem; margin: 0 0 6px; }
        p { color: var(--muted); line-height: 1.7; margin: 0 0 20px; font-size: .92rem; }
        .item { display: flex; align-items: center; gap: 14px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 16px; margin-bottom: 12px; }
        .item b { display: block; }
        .item small { color: var(--muted); }
        .item div { flex: 1; }
        a.btn { background: var(--accent); color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 10px; font-weight: bold; white-space: nowrap; }
        .none { color: var(--muted); padding: 16px; }
        footer { margin-top: 24px; font-size: .85rem; } footer a { color: var(--accent); }
    </style>
</head>
<body>
<main>
    <img class="logo" src="/favicon.svg" alt="">
    <h1>تطبيقات نظام معلومات الطلبة</h1>
    <p>للطلبة: النتائج والجدول والرسوم والإشعارات. وللموظفين والتدريسيين: النظام كاملاً. سجّل الدخول بنفس حسابك على الموقع. داخل الجامعة بدون إنترنت، اختر «الخادم» في صفحة الدخول واكتب عنوان خادم الجامعة.</p>
    @php($labels = ['android' => ['أندرويد', 'ملف APK، اسمح بالتثبيت من هذا المصدر عند الطلب.'], 'ios' => ['آيفون', 'غير موقّع: يُثبّت بأداة مثل Sideloadly أو AltStore.'], 'windows' => ['ويندوز', 'برنامج تثبيت لويندوز 10 و11 (64 بت).'], 'offline_server' => ['السيرفر الأوفلاين لويندوز', 'لتشغيل النظام على حاسوب داخل الجامعة بدون إنترنت. يحتاج رمز تفعيل.']])
    @foreach ($labels as $key => [$name, $hint])
        @continue(empty($releases[$key]['url']))
        <div class="item">
            <div><b>{{ $name }}</b><small>{{ $hint }} الإصدار {{ $releases[$key]['version'] ?? '' }}</small></div>
            <a class="btn" href="{{ $releases[$key]['url'] }}">تحميل</a>
        </div>
    @endforeach
    @if (! collect($labels)->keys()->contains(fn ($k) => ! empty($releases[$k]['url'])))
        <div class="none">لا توجد ملفات للتحميل بعد.</div>
    @endif
    <footer><a href="/login">← العودة لتسجيل الدخول</a></footer>
</main>
</body>
</html>
