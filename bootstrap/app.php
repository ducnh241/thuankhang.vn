<?php

use App\Http\Middleware\EnsureBackendAccess;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // routes/backend.php: toàn bộ khu vực quản trị, cùng middleware group 'web' như routes/web.php
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/backend.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        // Đổi mật khẩu (tự đổi hoặc admin đặt lại) → mọi phiên đăng nhập khác của tài khoản đó bị đăng xuất
        $middleware->web(append: [AuthenticateSession::class]);

        $middleware->alias([
            'backend.access' => EnsureBackendAccess::class,
        ]);

        // User đã đăng nhập mà vào /backend/login thì về dashboard
        $middleware->redirectUsersTo(fn () => route('backend.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Ô chữ bị gửi dạng mảng (request sửa tay, vd name[]=x): validate đã báo lỗi, nhưng nạp lại mảng vào form thì
        // Blade escape lỗi → trang 500. Bỏ các ô đó khỏi dữ liệu nạp lại; ô nào có rule 'array' (tags, permission…) giữ.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($e->response || $request->is('api/*') || $request->expectsJson()) {
                return null; // để Laravel xử lý như mặc định
            }
            $rules = $e->validator->getRules();
            $input = array_diff_key($request->input(), array_flip(['current_password', 'password', 'password_confirmation']));
            foreach ($input as $key => $value) {
                $expectsArray = collect($rules[$key] ?? ['array'])->contains(fn ($rule) => is_string($rule) && ($rule === 'array' || str_starts_with($rule, 'array:') || $rule === 'list'));
                if (is_array($value) && ! $expectsArray) {
                    unset($input[$key]);
                }
            }

            return redirect($e->redirectTo ?? url()->previous())->withInput($input)->withErrors($e->errors(), $e->errorBag);
        });
    })->create();
