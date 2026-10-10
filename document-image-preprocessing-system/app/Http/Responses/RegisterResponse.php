<?php

namespace App\Http\Responses;

use Illuminate\Auth\AuthManager;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse
{
    public function __construct(private readonly AuthManager $auth) {}

    /**
     * Sign the new user out and require an explicit login after registration.
     */
    public function toResponse($request): Response
    {
        $this->auth->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->wantsJson()) {
            return new JsonResponse([
                'message' => 'Tài khoản đã được tạo. Vui lòng đăng nhập để tiếp tục.',
            ], Response::HTTP_CREATED);
        }

        return redirect()
            ->route('login')
            ->with('status', 'Tài khoản đã được tạo thành công. Vui lòng đăng nhập để tiếp tục.');
    }
}
