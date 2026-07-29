<?php

use App\Http\Controllers\Api\LessonManifestController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->get('/lessons/{code}', LessonManifestController::class);
