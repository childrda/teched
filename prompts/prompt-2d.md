# Phase 2D — Local session authentication

A small phase with one job: give the app a real authenticated user so Phase 3 can hang persisted attempts off a `user_id` foreign key instead of an invented session identity.

Google sign-in arrives in Phase 6 and will attach to these same user rows through `google_id`. Nothing here should need migrating when it does.

Deliberately **not** in scope: registration, password reset, email verification, remember-me, roles-as-permissions, teacher dashboards, Filament. Do not install Laravel Breeze, Fortify, Jetstream, or Socialite. They bring their own Vite entries, Tailwind config, Blade layouts, and route files, which would collide with the player's existing styling for no benefit at this size.

---

## 1. Schema

One new migration. Do not edit Laravel's stock `0001_01_01_000000_create_users_table.php`.

Add to `users`:

- `role` — string, not null, default `'student'`. Least privilege: a user created without an explicit role is a student, never staff.
- `google_id` — string, nullable, unique. Unused until Phase 6; it exists now so linking a local account to a Google identity is an update rather than a migration. Match on this, never on email.

## 2. Role enum

`app/Enums/UserRole.php`, a backed string enum matching the style of `LessonStatus` — one case per line with a short docblock:

```php
enum UserRole: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Admin = 'admin';
}
```

Cast it on the `User` model. Add a case to the existing `EnumCastTest` alongside the other enum casts.

**Do not add `role` or `google_id` to `$fillable`.** Mass assignment of a privilege field is the classic escalation bug: any future endpoint that passes request data to `User::create()` or `update()` would let a caller name themselves an admin. Set both explicitly (`forceFill`, direct attribute assignment, or a dedicated method) in seeders and, later, admin code.

Add three small predicates — `isStudent()`, `isTeacher()`, `isAdmin()` — so call sites read as intent rather than enum comparisons. Nothing in this phase branches on them yet; Phase 3 and the teacher view will.

## 3. Login, logout

Hand-rolled, in `app/Http/Controllers/Auth/SessionController.php`:

- `create()` — renders the login view. Behind `guest` middleware so a signed-in user is redirected rather than shown a second login form.
- `store()` — validates `email` (required, email) and `password` (required, string), calls `Auth::attempt($credentials, remember: false)`, and on success calls `$request->session()->regenerate()` before redirecting to the intended URL. Session fixation protection is the whole reason `regenerate()` is not optional.
- `destroy()` — POST only. `Auth::logout()`, then `invalidate()` the session and `regenerateToken()`.

On failure, throw a validation error on `email` with a single generic message — credentials do not match. Never indicate whether the address exists; an enumerable user list is a gift to anyone probing a school district.

**Throttle the login POST.** Without Fortify nothing does this for us. `AppServiceProvider::boot()` is currently empty; register a named limiter there:

```php
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by(
        Str::lower((string) $request->input('email')).'|'.$request->ip()
    );
});
```

Apply it to the login POST only, via `->middleware('throttle:login')`. Key on the pair, not either alone: IP alone would let one Chromebook cart lock out a class, and email alone would let anyone lock a known account from anywhere.

On successful authentication, clear the limiter for that same key so a few mistyped passwords don't keep consuming the student's allowance after they get in. Put the key construction in one small helper used by both the limiter and the clear call — two copies of that string will drift.

Add no other auth routes. There is no `/register`, no `/forgot-password`, no `/email/verify`.

## 4. Login view

One Blade view. Match the player's existing visual language — reuse the `player-card`, `player-btn`, and `player-field-label` classes rather than inventing a second style vocabulary.

Accessibility is acceptance criteria here as in every other phase:

- An `<h1>`, real `<label for>` on both fields, and no placeholder-as-label.
- `autocomplete="email"` and `autocomplete="current-password"`, `type="email"`, `required`.
- Validation errors rendered in a summary at the top of the form, associated with the fields via `aria-describedby`, with focus moved to the summary on an error response so a screen reader user is told what happened rather than left on a silently re-rendered page.
- The generic failure message must read the same for a wrong password and an unknown email.

## 5. Route protection

- Wrap the player route and the grading route in `auth`.
- Wrap the JSON manifest route in `routes/api.php` in `auth` as well. It currently serves the redacted manifest **and a valid `grading_token`** to anyone who asks, which was harmless before 2C and is not now.
- An unauthenticated API request must get a JSON 401, never an HTML login page — an API consumer handed a login form has no way to react to it. `bootstrap/app.php` currently has an empty `withExceptions()`; render `AuthenticationException` there as a JSON 401 for `api/*` requests (or any request that expects JSON). Do not rely on the caller sending an `Accept: application/json` header for correct behavior.
- `bootstrap/app.php` also has an empty `withMiddleware()`. Configure both redirect destinations explicitly there: guests to the named login route, and authenticated users hitting `/login` to an existing named route. Laravel's default authenticated-user destination is `/dashboard`, which does not exist in this app. `routes/web.php` already serves `/` with the welcome view — give that route a name (`home`) and use it. **Do not build a dashboard for this phase.**
- Order matters on the grading route: `auth` must run before `throttle`. Laravel's throttle middleware builds its signature from the authenticated user when one is present and falls back to IP otherwise, so `->middleware(['auth', 'throttle:30,1'])` gives per-user limiting with no custom limiter. That closes the shared-cart problem a phase early. Do not hand-roll a rate limiter for this.
- Guests hitting a player URL should be redirected to login and returned to the lesson afterward, not shown a bare 403.

## 6. Session settings for shared devices

A Chromebook cart is the target hardware, so the default 120-minute session is long for a device that changes hands between periods.

- Set `SESSION_LIFETIME=60` in `.env.example` and note it in the README.
- Leave `SESSION_EXPIRE_ON_CLOSE` at `false`. Setting it true is better hygiene, but a closed lid would then force a re-login on every resume, and resume is the feature Phase 3 exists to deliver. Flag the trade-off in a comment rather than deciding it silently.
- Add a visible logout control and the signed-in user's name to the player header (`resources/views/lesson-player/show.blade.php`), as a real POST form with `@csrf`, not a link. On shared hardware an obvious logout does more for safety than any lifetime setting.

## 7. Seeded users

`database/seeders/UserSeeder.php`, called from `DatabaseSeeder`:

- One teacher, two students, all with a documented development password via `Hash::make()`. Never a real-looking secret, and it goes in the README next to the seeding instructions.
- `WeldingLessonSeeder` already does `User::query()->firstOrCreate(...)` for the publishing author — reconcile the two so the seeded lesson is published by the seeded teacher rather than creating a second orphan user. Match on email.
- Guard the seeder against running in production: refuse to run and say why when `app()->environment('production')`.

## 8. Tests

- The login page renders for a guest and redirects an authenticated user away.
- Valid credentials authenticate and land on the intended URL; the session id changes across login.
- Invalid password and unknown email produce the **same** error message and leave the user unauthenticated.
- The login POST is throttled after five attempts for the same email and IP.
- Logout invalidates the session and requires POST.
- A guest is redirected to login from the player and grading **web** routes. An unauthenticated request to the API manifest route receives a JSON **401** — no HTML, no `Location` header. An authenticated student reaches all three.
- The intended-URL round trip is asserted on the player GET route, where it matters. A POST redirect does not carry a useful intended destination, so don't assert it on the grading route.
- The grading-route guest assertion must be testing **authentication**, not CSRF. Laravel skips `VerifyCsrfToken` while running tests, so this normally needs nothing — but if a 419 appears, exclude only that middleware for that one assertion rather than reshaping the test.
- `role` casts to `UserRole`, and `google_id` enforces uniqueness.
- **Mass assignment guard.** Mass assignment of `role` or `google_id` must never alter either attribute. `AppServiceProvider` currently enables no strict model handling, so guarded attributes are silently discarded — but if strict discarded-attribute handling is enabled now or later, the same operation throws a `MassAssignmentException` instead. Both are safe. Assert whichever behavior this app is configured for, and above all prove that `role` cannot become `admin` and `google_id` cannot be set through mass input. Cover both `create()` and `update()`. **Do not weaken strict model handling to obtain a silent-discard assertion.**

  `google_id` is not a privilege field, but it is the identity-binding key Google sign-in will match on, so it needs the same protection as `role`.
- **Database default.** Creating a user without assigning `role` stores `student` and casts to `UserRole::Student` after a `refresh()`. The enum cast alone doesn't prove the column default works.
- **Existing tests will break.** `LessonPlayerTest`, `GradeBlockTest`, `PlacementRendererTest`, `ResponseBlockRendererTest`, `ReadAloudTest`, and the manifest contract tests all hit these routes as guests. Update them to act as a seeded student with `actingAs()`. This is mechanical, but it touches many files — do it thoroughly rather than loosening the middleware to keep tests passing.

## 9. Acceptance

- `php artisan test` and `npx vitest run` fully green.
- `php artisan migrate:fresh --seed` works; signing in as the seeded student reaches WEL 6.1.1 and grades the quiz end to end; signing out returns to login and the lesson URL is no longer reachable.
- No new Composer or npm dependencies.
- Nothing outside: the new migration, `UserRole`, the `User` model, the session controller, the login view, `routes/web.php`, `routes/api.php`, `bootstrap/app.php`, `AppServiceProvider`, session config and `.env.example`, `show.blade.php`, `UserSeeder`, `DatabaseSeeder`, `WeldingLessonSeeder`, the README, and tests. If a change appears to need more, stop and say so.
