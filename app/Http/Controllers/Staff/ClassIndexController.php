<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ClassIndexController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAny', SchoolClass::class);

        $classes = SchoolClass::query()
            ->visibleTo(Auth::user())
            ->withCount([
                'memberships as student_count' => fn ($q) => $q
                    ->where('role', \App\Enums\ClassRole::Student->value)
                    ->whereNull('withdrawn_at'),
                'assignments',
            ])
            ->orderBy('name')
            ->paginate(30);

        return view('staff.classes.index', [
            'classes' => $classes,
        ]);
    }
}
