<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\ProgressUpdateController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

// docs/12-api-spec.md
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:otp')->group(function () {
        Route::post('auth/otp/request', [AuthController::class, 'requestOtp']);
        Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp']);
    });

    Route::middleware(['auth:sanctum', 'throttle:field'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('sync/pull', [SyncController::class, 'pull']);
        Route::post('photos', [PhotoController::class, 'store'])->middleware('throttle:photos');
        Route::get('progress-updates', [ProgressUpdateController::class, 'index']);
        Route::post('progress-updates', [ProgressUpdateController::class, 'store']);

        Route::get('alerts', [AlertController::class, 'index']);
        Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);
        Route::post('alerts/{alert}/resolve', [AlertController::class, 'resolve']);

        Route::get('approvals', [ApprovalController::class, 'index']);
        Route::post('approvals/bulk-approve', [ApprovalController::class, 'bulkApprove']);
        Route::post('approvals/{update}/approve', [ApprovalController::class, 'approve']);
        Route::post('approvals/{update}/return', [ApprovalController::class, 'returnUpdate']);
        Route::post('approvals/{update}/reject', [ApprovalController::class, 'reject']);
    });
});
