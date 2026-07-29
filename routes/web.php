<?php

use App\Http\Controllers\LessonPlayerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Student player. Unauthenticated for now; auth arrives in Phase 6.
Route::get('/lessons/{code}', LessonPlayerController::class)->name('lessons.play');
