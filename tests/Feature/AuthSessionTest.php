<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\LessonPublisher;
use Database\Seeders\UserSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => $this->withoutVite());

function makeStudent(string $email = 'learner@teched.test', string $password = 'password'): User
{
    $user = User::factory()->create([
        'email' => $email,
        'password' => Hash::make($password),
    ]);

    // Factory cannot mass-assign role; the DB default is student.
    expect($user->fresh()->role)->toBe(UserRole::Student);

    return $user;
}

test('the login page renders for a guest and redirects an authenticated user away', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign in', false)
        ->assertSee('autocomplete="email"', false)
        ->assertSee('autocomplete="current-password"', false);

    $this->actingAs(makeStudent());

    $this->get('/login')->assertRedirect(route('home'));
});

test('valid credentials authenticate, regenerate the session, and honour the intended URL', function () {
    $user = makeStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $this->get("/lessons/{$lesson->code}")
        ->assertRedirect(route('login'));

    $sessionBefore = session()->getId();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect("/lessons/{$lesson->code}");
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionBefore);
});

test('invalid password and unknown email produce the same error and leave the user unauthenticated', function (array $credentials) {
    makeStudent('known@teched.test');

    $response = $this->from('/login')->post('/login', $credentials);

    $response->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $message = session('errors')->first('email');

    expect($message)->toBe(__('auth.failed'));
    $this->assertGuest();
})->with([
    'wrong password' => [['email' => 'known@teched.test', 'password' => 'nope']],
    'unknown email' => [['email' => 'nobody@teched.test', 'password' => 'password']],
]);

test('the login POST is throttled after five attempts for the same email and IP', function () {
    $user = makeStudent();

    for ($i = 0; $i < 5; $i++) {
        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect('/login');
    }

    $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);
});

test('logout invalidates the session and requires POST', function () {
    $user = makeStudent();
    $this->actingAs($user);

    $this->get('/logout')->assertMethodNotAllowed();

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('guests are redirected from the player and grading web routes', function () {
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $quiz = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz');

    $this->get("/lessons/{$lesson->code}")
        ->assertRedirect(route('login'));

    // Web POST (not JSON): guests are sent to login, not given a 401 page.
    $this->post("/player/lessons/{$lesson->code}/blocks/{$quiz['block_id']}/grade", [
        'version_token' => 'unused',
        'response' => [],
    ])->assertRedirect(route('login'));
});

test('an unauthenticated API manifest request receives a JSON 401 with no redirect', function () {
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $response = $this->get('/api/lessons/'.$lesson->code, [
        'Accept' => 'text/html',
    ]);

    $response->assertUnauthorized()
        ->assertHeaderMissing('Location')
        ->assertJson(['message' => 'Unauthenticated.']);

    expect($response->headers->get('content-type'))->toContain('application/json');
});

test('an authenticated student reaches the player, API, and grading routes', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $this->get("/lessons/{$lesson->code}")->assertOk();
    $this->getJson("/api/lessons/{$lesson->code}")->assertOk();

    $payload = app(App\Services\StudentManifest::class)->forLesson($lesson->fresh());
    $quiz = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz');
    $answers = [];

    foreach ($quiz['config']['questions'] as $question) {
        $answers[$question['id']] = $question['answer_id'];
    }

    $this->postJson("/player/lessons/{$lesson->code}/blocks/{$quiz['block_id']}/grade", [
        'version_token' => $payload['grading_token'],
        'response' => $answers,
    ])->assertOk();

    expect($user->isStudent())->toBeTrue();
});

test('UserSeeder refuses to run in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new UserSeeder())->run())
        ->toThrow(RuntimeException::class, 'production');
});

test('an authenticated user sees logout on home and the lesson player; a guest sees neither', function () {
    $user = User::factory()->create();
    $user->forceFill(['name' => 'Casey Student'])->save();
    asStudent($user->fresh());

    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    $this->get('/')
        ->assertOk()
        ->assertSee('Casey Student', false)
        ->assertSee('Sign out', false)
        ->assertSee('method="POST"', false)
        ->assertSee(route('logout'), false);

    $this->get("/lessons/{$lesson->code}")
        ->assertOk()
        ->assertSee('Casey Student', false)
        ->assertSee('Sign out', false);

    $this->post('/logout')->assertRedirect(route('login'));

    $this->get('/')->assertRedirect(route('login'));
    $this->get('/login')->assertOk()->assertDontSee('Sign out', false);
});

test('the login response carries no-store cache headers', function () {
    $response = $this->get('/login')->assertOk()->assertHeader('Pragma', 'no-cache');

    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('no-cache')
        ->and($cacheControl)->toContain('must-revalidate');
});

test('a TokenMismatchException on a web login POST redirects a guest to login with an expired-form notice', function () {
    // VerifyCsrfToken is skipped while runningUnitTests(), so exercise the
    // exception handler the same way a real mismatch would reach it.
    $request = Request::create('/login', 'POST');
    $request->setLaravelSession($this->app['session.store']);

    $response = app(ExceptionHandler::class)->render(
        $request,
        new TokenMismatchException('CSRF token mismatch.')
    );

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('login'));

    // Follow the flash onto the login page for the screen-reader summary.
    $this->withSession(['auth_notice' => 'This form expired. Please sign in again.'])
        ->get('/login')
        ->assertOk()
        ->assertSee('This form expired. Please sign in again.', false)
        ->assertSee('id="login-notice"', false)
        ->assertDontSee('border-amber-800', false);
});

test('a TokenMismatchException while authenticated redirects to home', function () {
    $user = makeStudent();

    $request = Request::create('/login', 'POST');
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn () => $user);

    $response = app(ExceptionHandler::class)->render(
        $request,
        new TokenMismatchException('CSRF token mismatch.')
    );

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('home'));
});

test('a JSON player write with a bad token still receives 419, not a redirect', function () {
    $user = asStudent();
    $lesson = createLessonWithAllBlockTypes();
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());
    $attempt = app(App\Services\AttemptService::class)
        ->resolveForPlayer($user, $lesson->fresh())['attempt'];

    $request = Request::create(
        "/player/attempts/{$attempt->id}/activity",
        'POST',
        ['active_seconds_delta' => 1],
        server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]
    );
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn () => $user);

    $response = app(ExceptionHandler::class)->render(
        $request,
        new TokenMismatchException('CSRF token mismatch.')
    );

    expect($response->getStatusCode())->toBe(419)
        ->and($response->headers->get('Location'))->toBeNull();
});

