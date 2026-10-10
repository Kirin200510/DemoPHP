<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuickLoginController extends Controller
{
    /**
     * Sign in with a seeded demo account during local development.
     */
    public function __invoke(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $demoEmails = [
            'admin' => 'admin@example.com',
            'processor' => 'processor@example.com',
            'customer' => 'test@example.com',
        ];

        $email = $demoEmails[$role] ?? null;

        abort_if($email === null, 404);

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if ($user === null || ! $user->hasRole($role)) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Tài khoản demo chưa được tạo. Hãy chạy php artisan migrate --seed sau khi bật MySQL.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('documents.create'));
    }
}
