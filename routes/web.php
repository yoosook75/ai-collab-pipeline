<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\OpsUnblockController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UnlockController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [PostController::class, 'index'])->name('posts.index');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('throttle:10,1')->group(function () {
    Route::get('/unlock', [UnlockController::class, 'create'])->name('unlock.request');
    Route::post('/unlock', [UnlockController::class, 'store']);
    Route::get('/unlock/verify', [UnlockController::class, 'edit'])->name('unlock.verify');
    Route::post('/unlock/verify', [UnlockController::class, 'update']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::view('/email/verify', 'auth.verify-email')->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('posts.index');
    })->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', '인증 메일을 다시 보냈습니다.');
    })->middleware('throttle:6,1')->name('verification.send');

    Route::middleware('verified')->group(function () {
        Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create');
        Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
    });
});

Route::get('/ops/unblock/{ip}', OpsUnblockController::class)
    ->middleware('signed')
    ->where('ip', '[0-9a-fA-F:.]+')
    ->name('ops.unblock');

// Unmatched paths still pass through the web middleware (IP blocking) before 404.
Route::fallback(fn () => abort(404));
