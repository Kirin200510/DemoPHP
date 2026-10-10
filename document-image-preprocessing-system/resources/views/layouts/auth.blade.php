<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Đăng nhập')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 font-sans text-slate-100 antialiased">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-5 py-10">
        <div class="w-full rounded-3xl border border-white/10 bg-white/[0.06] p-6 shadow-2xl shadow-cyan-950/30 backdrop-blur sm:p-8">
            <a href="{{ route('login') }}" class="mb-8 flex items-center gap-3">
                <span class="grid size-10 place-items-center rounded-xl bg-cyan-400 text-lg font-black text-slate-950">AI</span>
                <span>
                    <span class="block text-sm font-semibold tracking-wide text-white">Document Lab</span>
                    <span class="block text-xs text-slate-400">Image preprocessing</span>
                </span>
            </a>

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</body>
</html>
