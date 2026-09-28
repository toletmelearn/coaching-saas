<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Coaching SaaS') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-width: 360px;
            font-family: system-ui, -apple-system, sans-serif;
            padding: 1rem;
        }
        main { max-width: 480px; margin: 0 auto; }
        label { display: block; margin-top: 0.75rem; font-weight: 600; }
        input, select { width: 100%; padding: 0.5rem; margin-top: 0.25rem; }
        button { margin-top: 1rem; padding: 0.5rem 1rem; }
        .errors { color: #b00020; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 0.5rem; text-align: left; border-bottom: 1px solid #ccc; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
