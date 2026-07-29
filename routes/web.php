<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\GradeBlockController;
use App\Http\Controllers\LessonPlayerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:login');
});

Route::post('/logout', [SessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/lessons/{code}', LessonPlayerController::class)->name('lessons.play');

    // Group auth runs before this throttle, so the limiter keys per user
    // rather than per shared-cart IP.
    Route::post('/player/lessons/{code}/blocks/{blockId}/grade', GradeBlockController::class)
        ->middleware('throttle:30,1')
        ->name('player.blocks.grade');
});
