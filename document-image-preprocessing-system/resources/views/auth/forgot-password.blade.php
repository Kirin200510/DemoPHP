@extends('layouts.auth')

@section('title', 'Quên mật khẩu')

@section('content')
    <h1 class="text-2xl font-bold text-white">Quên mật khẩu</h1>
    <p class="mt-2 text-sm text-slate-400">Nhập email để nhận liên kết đặt lại mật khẩu.</p>

    @if (session('status'))
        <div class="mt-5 rounded-xl border border-emerald-400/25 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-7 flex flex-col gap-5">
        @csrf
        <label class="flex flex-col gap-2 text-sm text-slate-300">
            Email
            <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="h-11 rounded-xl border border-white/10 bg-slate-900/80 px-3 text-white outline-none ring-cyan-400 focus:ring-2">
        </label>
        <button type="submit" class="h-11 rounded-xl bg-cyan-400 font-semibold text-slate-950 transition hover:bg-cyan-300">Gửi liên kết</button>
    </form>

    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" class="text-cyan-300 hover:text-cyan-200">Quay lại đăng nhập</a></p>
@endsection
