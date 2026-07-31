<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\BlockSubmission;
use App\Models\LessonAttempt;
use App\Services\SubmissionReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records an immutable review for one short_response/cer submission.
 * Authorization: LessonAttemptPolicy::intervene — exact assignment class.
 */
class ReviewBlockSubmissionController extends Controller
{
    public function __construct(private readonly SubmissionReviewService $reviews) {}

    public function __invoke(
        Request $request,
        LessonAttempt $attempt,
        BlockSubmission $submission,
    ): RedirectResponse {
        Gate::authorize('intervene', $attempt);

        if ((int) $submission->lesson_attempt_id !== (int) $attempt->getKey()) {
            abort(404);
        }

        // points_possible is never accepted from the client — derive server-side.
        if ($request->exists('points_possible')) {
            throw ValidationException::withMessages([
                'points_possible' => 'points_possible cannot be submitted; it is derived from the pinned block.',
            ]);
        }

        $payload = $request->validate([
            'mode' => ['required', Rule::in([
                SubmissionReviewService::MODE_REVIEW_ONLY,
                SubmissionReviewService::MODE_SCORED,
            ])],
            'points_awarded' => ['nullable', 'integer', 'min:0'],
            'comment' => ['nullable', 'string', 'max:'.(int) config('submission-reviews.comment_max', 5000)],
            'private_note' => ['nullable', 'string', 'max:'.(int) config('submission-reviews.comment_max', 5000)],
        ]);

        $this->reviews->review(
            $attempt,
            $submission,
            Auth::user(),
            $payload['mode'],
            array_key_exists('points_awarded', $payload) && $payload['points_awarded'] !== null
                ? (int) $payload['points_awarded']
                : null,
            $payload['comment'] ?? null,
            $payload['private_note'] ?? null,
        );

        return redirect()
            ->route('staff.attempts.show', $attempt)
            ->with('status', __('staff.review_recorded'));
    }
}
