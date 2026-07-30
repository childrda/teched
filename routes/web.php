<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\GradeBlockController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LessonPlayerController;
use App\Http\Controllers\Player\AssignmentPlayerController;
use App\Http\Controllers\Player\ContinuePageController;
use App\Http\Controllers\Player\RecordActivityController;
use App\Http\Controllers\Player\SaveBlockStateController;
use App\Http\Controllers\Staff\AssignmentShowController;
use App\Http\Controllers\Staff\AttemptShowController;
use App\Http\Controllers\Staff\BlockedAttemptsController;
use App\Http\Controllers\Staff\ClassAssignmentsController;
use App\Http\Controllers\Staff\ClassIndexController;
use App\Http\Controllers\Staff\ConfirmReopenController;
use App\Http\Controllers\Staff\ConfirmRestartController;
use App\Http\Controllers\Staff\GrantRetriesController;
use App\Http\Controllers\Staff\ReopenAttemptController;
use App\Http\Controllers\Staff\RestartAttemptController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->middleware('auth')->name('home');

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

    Route::get('/authoring/lessons/{lesson}/preview', \App\Http\Controllers\Authoring\LessonPreviewController::class)
        ->name('authoring.lessons.preview');

    Route::get('/player/assignments/{assignment}', AssignmentPlayerController::class)
        ->name('player.assignments.show');

    // Group auth runs before this throttle, so the limiter keys per user
    // rather than per shared-cart IP.
    Route::post('/player/lessons/{code}/blocks/{blockId}/grade', GradeBlockController::class)
        ->middleware('throttle:30,1')
        ->name('player.blocks.grade');

    // Player writes stay on the web stack (session + CSRF). Do not move them
    // to routes/api.php — that group has StartSession but no VerifyCsrfToken.
    // throttle:120,1 is a runaway guard for chatty autosave, not a security control.
    Route::put('/player/attempts/{attempt}/blocks/{blockId}/state', SaveBlockStateController::class)
        ->middleware('throttle:120,1')
        ->name('player.blocks.state');

    Route::post('/player/attempts/{attempt}/activity', RecordActivityController::class)
        ->middleware('throttle:60,1')
        ->name('player.attempts.activity');

    Route::post('/player/attempts/{attempt}/pages/{pageId}/continue', ContinuePageController::class)
        ->middleware('throttle:60,1')
        ->name('player.pages.continue');
});

Route::middleware(['auth', 'staff'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/classes', ClassIndexController::class)->name('classes.index');
    Route::get('/classes/{schoolClass}/assignments', ClassAssignmentsController::class)
        ->name('classes.assignments');
    Route::get('/assignments/{assignment}', AssignmentShowController::class)
        ->name('assignments.show');
    Route::get('/attempts/{attempt}', AttemptShowController::class)->name('attempts.show');

    Route::get('/blocked-attempts', BlockedAttemptsController::class)->name('blocked-attempts');

    Route::post('/attempts/{attempt}/grant-retries', GrantRetriesController::class)
        ->name('attempts.grant-retries');
    Route::get('/attempts/{attempt}/restart/confirm', ConfirmRestartController::class)
        ->name('attempts.restart.confirm');
    Route::post('/attempts/{attempt}/restart', RestartAttemptController::class)
        ->name('attempts.restart');
    Route::get('/attempts/{attempt}/reopen/confirm', ConfirmReopenController::class)
        ->name('attempts.reopen.confirm');
    Route::post('/attempts/{attempt}/reopen', ReopenAttemptController::class)
        ->name('attempts.reopen');
});
