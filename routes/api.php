<?php

use App\Http\Controllers\Api\LessonManifestController;
use Illuminate\Support\Facades\Route;

Route::get('/lessons/{code}', LessonManifestController::class);
