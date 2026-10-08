<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Xử lý ảnh giấy tờ')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 font-sans text-slate-100 antialiased">
    <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
        <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-cyan-500/15 blur-3xl"></div>
        <div class="absolute -right-32 top-1/3 h-96 w-96 rounded-full bg-blue-600/15 blur-3xl"></div>
    </div>

    <header class="relative border-b border-white/10 bg-slate-950/75 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-5 lg:px-8">
            <a href="{{ route('documents.create') }}" class="flex items-center gap-3">
                <span class="grid size-10 place-items-center rounded-xl bg-cyan-400 text-lg font-black text-slate-950">AI</span>
                <span>
                    <span class="block text-sm font-semibold tracking-wide text-white">Document Lab</span>
                    <span class="block text-xs text-slate-400">Image preprocessing</span>
                </span>
            </a>
            <span class="rounded-full border border-emerald-400/25 bg-emerald-400/10 px-3 py-1 text-xs font-medium text-emerald-300">
                Laravel + AI Engine
            </span>
        </div>
    </header>

    <main class="relative mx-auto max-w-7xl px-5 py-10 lg:px-8 lg:py-14">
        @yield('content')
    </main>
</body>
</html>
