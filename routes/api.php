<?php

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * API quản trị sản phẩm / danh mục (tiền tố /api). Xác thực: "Authorization: Bearer tk_…" (tạo ở Hồ sơ trong admin).
 * Tài liệu: docs/api-san-pham.md — skill Claude: skills/thuankhang-products.
 */
Route::prefix('v1')->middleware([AuthenticateApiToken::class, 'throttle:120,1'])->group(function () {
    // Kiểm tra token: tài khoản + các quyền sản phẩm
    Route::get('me', fn (Request $request) => response()->json(['data' => [
        'name' => $request->user()->name,
        'email' => $request->user()->email,
        'abilities' => collect(['product', 'product/add', 'product/edit', 'product/delete'])->filter(fn ($p) => $request->user()->can($p))->values(),
    ]]));

    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::get('categories/{category}', [CategoryController::class, 'show'])->whereNumber('category');
    Route::match(['put', 'patch'], 'categories/{category}', [CategoryController::class, 'update'])->whereNumber('category');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update']);
    Route::delete('products/{product}', [ProductController::class, 'destroy']);
});
