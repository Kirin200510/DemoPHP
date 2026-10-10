@extends('layouts.auth')

@section('title', 'Đăng nhập')

@section('content')
    <h1 class="text-2xl font-bold text-white">Đăng nhập</h1>
    <p class="mt-2 text-sm text-slate-400">Đăng nhập để quản lý hồ sơ giấy tờ của bạn.</p>

    @if (session('status'))
        <div class="mt-5 rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-7 flex flex-col gap-5">
        @csrf
        <label class="flex flex-col gap-2 text-sm text-slate-300">
            Email
            <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="h-11 rounded-xl border border-white/10 bg-slate-900/80 px-3 text-white outline-none ring-cyan-400 focus:ring-2">
        </label>
        <label class="flex flex-col gap-2 text-sm text-slate-300">
            Mật khẩu
            <span class="relative">
                <input id="login-password" name="password" type="password" required autocomplete="current-password" class="h-11 w-full rounded-xl border border-white/10 bg-slate-900/80 px-3 pr-16 text-white outline-none ring-cyan-400 focus:ring-2">
                <button type="button" data-password-toggle="login-password" aria-controls="login-password" aria-label="Hiện mật khẩu" aria-pressed="false" title="Hiện mật khẩu" class="absolute inset-y-0 right-2 my-1 rounded-lg px-2 text-cyan-300 transition hover:bg-white/10 hover:text-cyan-200">
                    <svg data-password-eye class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    <svg data-password-eye-off class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 5.1A10.7 10.7 0 0 1 12 4.9c6.25 0 9.75 7.1 9.75 7.1a17.4 17.4 0 0 1-3.06 3.93M6.61 6.62C3.75 8.43 2.25 12 2.25 12s3.5 7.1 9.75 7.1c1.03 0 1.97-.16 2.82-.43"/></svg>
                </button>
            </span>
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-400">
            <input name="remember" type="checkbox" value="1" class="rounded border-white/20 bg-slate-900 text-cyan-400">
            Ghi nhớ đăng nhập
        </label>
        <button type="submit" class="h-11 rounded-xl bg-cyan-400 font-semibold text-slate-950 transition hover:bg-cyan-300">Đăng nhập</button>
    </form>

    @if (app()->environment(['local', 'testing']))
        <div class="mt-7 border-t border-white/10 pt-6">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Đăng nhập nhanh (demo local)</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-3">
                @foreach ([
                    'admin' => 'Admin',
                    'processor' => 'Nhân viên xử lý',
                    'customer' => 'Khách hàng',
                ] as $role => $label)
                    <form method="POST" action="{{ route('login.quick', ['role' => $role]) }}">
                        @csrf
                        <button type="submit" class="w-full rounded-xl border border-white/10 bg-slate-900/70 px-3 py-2 text-xs font-medium text-slate-300 transition hover:border-cyan-300/40 hover:text-cyan-200">
                            {{ $label }}
                        </button>
                    </form>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-slate-500">Các nút này chỉ hiện ở local/testing và không có trên production.</p>
        </div>
    @endif

    <div class="mt-6 flex justify-between gap-4 text-sm">
        <a href="{{ route('password.request') }}" class="text-cyan-300 hover:text-cyan-200">Quên mật khẩu?</a>
        <a href="{{ route('register') }}" class="text-cyan-300 hover:text-cyan-200">Tạo tài khoản</a>
    </div>
@endsection
