@extends('layouts.auth')

@section('title', 'Đặt lại mật khẩu')

@section('content')
    <h1 class="text-2xl font-bold text-white">Đặt lại mật khẩu</h1>

    <form method="POST" action="{{ route('password.update') }}" class="mt-7 flex flex-col gap-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label class="flex flex-col gap-2 text-sm text-slate-300">Email
            <input name="email" type="email" value="{{ old('email', $request->email) }}" required autocomplete="email" class="h-11 rounded-xl border border-white/10 bg-slate-900/80 px-3 text-white outline-none ring-cyan-400 focus:ring-2">
        </label>
        <label class="flex flex-col gap-2 text-sm text-slate-300">Mật khẩu mới
            <span class="relative">
                <input id="reset-password" name="password" type="password" required autocomplete="new-password" class="h-11 w-full rounded-xl border border-white/10 bg-slate-900/80 px-3 pr-16 text-white outline-none ring-cyan-400 focus:ring-2">
                <button type="button" data-password-toggle="reset-password" aria-controls="reset-password" aria-label="Hiện mật khẩu" aria-pressed="false" title="Hiện mật khẩu" class="absolute inset-y-0 right-2 my-1 rounded-lg px-2 text-cyan-300 transition hover:bg-white/10 hover:text-cyan-200">
                    <svg data-password-eye class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    <svg data-password-eye-off class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 5.1A10.7 10.7 0 0 1 12 4.9c6.25 0 9.75 7.1 9.75 7.1a17.4 17.4 0 0 1-3.06 3.93M6.61 6.62C3.75 8.43 2.25 12 2.25 12s3.5 7.1 9.75 7.1c1.03 0 1.97-.16 2.82-.43"/></svg>
                </button>
            </span>
        </label>
        <label class="flex flex-col gap-2 text-sm text-slate-300">Xác nhận mật khẩu mới
            <span class="relative">
                <input id="reset-password-confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="h-11 w-full rounded-xl border border-white/10 bg-slate-900/80 px-3 pr-16 text-white outline-none ring-cyan-400 focus:ring-2">
                <button type="button" data-password-toggle="reset-password-confirmation" aria-controls="reset-password-confirmation" aria-label="Hiện mật khẩu" aria-pressed="false" title="Hiện mật khẩu" class="absolute inset-y-0 right-2 my-1 rounded-lg px-2 text-cyan-300 transition hover:bg-white/10 hover:text-cyan-200">
                    <svg data-password-eye class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    <svg data-password-eye-off class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 5.1A10.7 10.7 0 0 1 12 4.9c6.25 0 9.75 7.1 9.75 7.1a17.4 17.4 0 0 1-3.06 3.93M6.61 6.62C3.75 8.43 2.25 12 2.25 12s3.5 7.1 9.75 7.1c1.03 0 1.97-.16 2.82-.43"/></svg>
                </button>
            </span>
        </label>
        <button type="submit" class="h-11 rounded-xl bg-cyan-400 font-semibold text-slate-950 transition hover:bg-cyan-300">Cập nhật mật khẩu</button>
    </form>
@endsection
