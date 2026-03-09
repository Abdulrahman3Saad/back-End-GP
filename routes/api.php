<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ServiceController;
use App\Http\Controllers\API\CarController;
use App\Http\Controllers\API\CarSwapController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\PaymentController;
use Illuminate\Support\Facades\Route;

// ===================================================
// Public Routes (بدون تسجيل دخول)
// ===================================================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::get('/services',   [ServiceController::class, 'index']);
Route::get('/cars',       [CarController::class, 'index']);
Route::get('/cars/{car}', [CarController::class, 'show']);

// ===================================================
// Protected Routes (بعد تسجيل الدخول)
// ===================================================
Route::middleware('auth:sanctum')->group(function () {

    // ---- Auth ----
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // ================================================
    // Cars
    // ================================================
    Route::post('/cars',         [CarController::class, 'store']);
    // ✅ POST بدل PUT عشان الصور بتتبعت كـ multipart — Laravel مش بيدعم PUT مع files
    Route::post('/cars/{car}',   [CarController::class, 'update']);
    Route::delete('/cars/{car}', [CarController::class, 'destroy']);
    Route::get('/my-cars',       [CarController::class, 'myCars']);

    // Admin — Cars
    Route::prefix('admin')->group(function () {
        Route::get('/cars/pending',        [CarController::class, 'pendingCars']);
        Route::post('/cars/{car}/approve', [CarController::class, 'approveCar']);
        Route::post('/cars/{car}/reject',  [CarController::class, 'rejectCar']);
    });

    // ================================================
    // Services (الأدمن فقط)
    // ================================================
    Route::post('/services',             [ServiceController::class, 'store']);
    Route::put('/services/{service}',    [ServiceController::class, 'update']);
    Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

    // ================================================
    // Orders
    // ================================================

    // ✅ Static routes لازم تجي قبل {order} — مهم جداً
    Route::post('/orders/auto-expire',  [OrderController::class, 'autoExpire']);
    Route::get('/my-orders',            [OrderController::class, 'myOrders']);
    Route::get('/incoming-orders',      [OrderController::class, 'incomingOrders']);

    // Dynamic routes
    Route::post('/orders',                              [OrderController::class, 'store']);
    Route::get('/orders/{order}',                       [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel',               [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/approve',              [OrderController::class, 'approve']);
    Route::post('/orders/{order}/reject',               [OrderController::class, 'reject']);
    Route::post('/orders/{order}/complete',             [OrderController::class, 'complete']);
    Route::post('/orders/{order}/confirm-receive',      [OrderController::class, 'confirmReceive']);
    Route::post('/orders/{order}/mark-delivered',       [OrderController::class, 'markDelivered']);
    Route::post('/orders/{order}/cancel-by-agreement',  [OrderController::class, 'cancelByAgreement']);

    // Admin — Orders
    Route::get('/admin/orders', [OrderController::class, 'index']);

    // ================================================
    // Payments
    // ================================================

    // ✅ Static route قبل {payment}
    Route::get('/admin/payments/service-fees-stats', [PaymentController::class, 'serviceFeesStats']);
    Route::get('/admin/payments',                    [PaymentController::class, 'index']);

    Route::post('/payments',          [PaymentController::class, 'store']);
    Route::get('/my-payments',        [PaymentController::class, 'myPayments']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);

    // ================================================
    // Car Swaps — User Routes
    // ================================================
    Route::prefix('swaps')->group(function () {
        // ✅ Static routes قبل {carSwap}
        Route::get('/sent',     [CarSwapController::class, 'mySentSwaps']);
        Route::get('/received', [CarSwapController::class, 'myReceivedSwaps']);

        Route::post('/',                   [CarSwapController::class, 'store']);
        Route::get('/{carSwap}',           [CarSwapController::class, 'show']);
        Route::post('/{carSwap}/accept',   [CarSwapController::class, 'accept']);
        Route::post('/{carSwap}/reject',   [CarSwapController::class, 'reject']);
        Route::post('/{carSwap}/cancel',   [CarSwapController::class, 'cancel']);
        Route::post('/{carSwap}/complete', [CarSwapController::class, 'complete']);
    });

    // Admin — Swaps
    Route::get('/admin/swaps',                    [CarSwapController::class, 'index']);
    Route::post('/admin/swaps/{carSwap}/approve', [CarSwapController::class, 'adminApprove']);
    Route::post('/admin/swaps/{carSwap}/reject',  [CarSwapController::class, 'adminReject']);
});
