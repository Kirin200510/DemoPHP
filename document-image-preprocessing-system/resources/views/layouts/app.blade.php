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
            <div class="flex items-center gap-3">
                <span class="rounded-full border border-emerald-400/25 bg-emerald-400/10 px-3 py-1 text-xs font-medium text-emerald-300">
                    Laravel + AI Engine
                </span>
                @auth
                    <span class="hidden text-sm text-slate-300 sm:inline">{{ auth()->user()->name }}</span>
                    @role('admin')
                        <a href="{{ route('admin.users') }}" class="text-sm text-cyan-300 transition hover:text-cyan-200">Quản trị</a>
                    @endrole
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-slate-400 transition hover:text-white">Đăng xuất</button>
                    </form>
                @endauth
            </div>
        </div>
    </header>

    <div id="flash-notifications" class="pointer-events-none fixed right-5 top-5 z-50 flex w-[calc(100%-2.5rem)] max-w-sm flex-col gap-3">
        @if (session('success'))
            <div data-auto-dismiss class="pointer-events-auto flex items-start gap-3 rounded-2xl border border-emerald-400/30 bg-slate-950/95 px-5 py-4 text-sm text-emerald-100 shadow-2xl shadow-emerald-950/30 backdrop-blur transition duration-300" role="status">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-emerald-400 font-bold text-slate-950">✓</span>
                <div>
                    <p class="font-semibold text-emerald-200">Thao tác thành công</p>
                    <p class="mt-1 text-emerald-100/85">{{ session('success') }}</p>
                </div>
            </div>
        @endif

        @if (session('warning'))
            <div data-auto-dismiss class="pointer-events-auto flex items-start gap-3 rounded-2xl border border-amber-400/30 bg-slate-950/95 px-5 py-4 text-sm text-amber-100 shadow-2xl shadow-amber-950/30 backdrop-blur transition duration-300" role="alert">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-amber-400 font-bold text-slate-950">!</span>
                <div>
                    <p class="font-semibold text-amber-200">Lưu ý</p>
                    <p class="mt-1 text-amber-100/85">{{ session('warning') }}</p>
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div data-auto-dismiss class="pointer-events-auto flex items-start gap-3 rounded-2xl border border-red-400/30 bg-slate-950/95 px-5 py-4 text-sm text-red-100 shadow-2xl shadow-red-950/30 backdrop-blur transition duration-300" role="alert">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-red-400 font-bold text-slate-950">!</span>
                <div>
                    <p class="font-semibold text-red-200">Không thể hoàn tất thao tác</p>
                    <p class="mt-1 text-red-100/85">{{ $errors->first() }}</p>
                </div>
            </div>
        @endif
    </div>

    <main class="relative mx-auto max-w-7xl px-5 py-10 lg:px-8 lg:py-14">

        @yield('content')
    </main>

    <script>
        (() => {
            const scrollKey = 'document-lab-scroll-position';
            const currentLocation = `${window.location.pathname}${window.location.search}`;
            const storedScroll = sessionStorage.getItem(scrollKey);

            if (storedScroll) {
                const saved = JSON.parse(storedScroll);
                sessionStorage.removeItem(scrollKey);

                if (saved.path === currentLocation) {
                    window.requestAnimationFrame(() => window.scrollTo(0, saved.y));
                }
            }

            document.querySelectorAll('form').forEach((form) => {
                form.addEventListener('submit', () => {
                    sessionStorage.setItem(scrollKey, JSON.stringify({
                        path: currentLocation,
                        y: window.scrollY,
                    }));
                });
            });

            document.querySelectorAll('[data-auto-dismiss]').forEach((notification) => {
                window.setTimeout(() => {
                    notification.classList.add('translate-y-2', 'opacity-0');
                    window.setTimeout(() => notification.remove(), 300);
                }, 5000);
            });
        })();
    </script>
</body>
</html>
