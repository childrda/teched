<?php

namespace App\Providers;

use App\Http\Controllers\Auth\SessionController;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonAttempt;
use App\Models\SchoolClass;
use App\Policies\LessonAssignmentPolicy;
use App\Policies\LessonAttemptPolicy;
use App\Policies\LessonPolicy;
use App\Policies\SchoolClassPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(SchoolClass::class, SchoolClassPolicy::class);
        Gate::policy(LessonAssignment::class, LessonAssignmentPolicy::class);
        Gate::policy(LessonAttempt::class, LessonAttemptPolicy::class);
        Gate::policy(Lesson::class, LessonPolicy::class);

        // Livewire temp uploads must clear the document cap or oversized files
        // are rejected before LessonAssetService can explain why.
        config([
            'livewire.temporary_file_upload.rules' => [
                'required',
                'file',
                'max:'.(int) config('lesson-assets.document_max_kb'),
            ],
        ]);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(SessionController::throttleKey($request));
        });
    }
}
