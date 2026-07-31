<?php

use App\Enums\ClassRole;
use App\Enums\UserRole;
use App\Exceptions\ImmutableUserAccountChangeException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\ClassMembership;
use App\Models\User;
use App\Models\UserAccountChange;
use App\Services\ClassMembershipService;
use App\Services\SchoolClassService;
use App\Services\UserAccountService;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

test('admin can create teacher with and without password; email is lowercase', function () {
    $admin = asAdmin();
    $accounts = app(UserAccountService::class);

    $withPassword = $accounts->createProvisionedUser([
        'name' => 'Pat Teacher',
        'email' => 'Pat.Teacher@Example.ORG',
        'role' => UserRole::Teacher,
        'password' => 'secret-pass',
    ], $admin);

    expect($withPassword->email)->toBe('pat.teacher@example.org')
        ->and($withPassword->password)->not->toBeNull()
        ->and(Hash::check('secret-pass', $withPassword->password))->toBeTrue()
        ->and($withPassword->google_id)->toBeNull()
        ->and(UserAccountChange::query()->where('user_id', $withPassword->id)->where('action', 'created')->count())->toBe(1);

    $awaiting = $accounts->createProvisionedUser([
        'name' => 'Google Teacher',
        'email' => 'google.teacher@example.org',
        'role' => UserRole::Teacher,
        'password' => null,
    ], $admin);

    expect($awaiting->password)->toBeNull()
        ->and($awaiting->google_id)->toBeNull()
        ->and(app(UserAccountService::class)->awaitingGoogleSignIn($awaiting))->toBeTrue();

    expect(fn () => $accounts->createProvisionedUser([
        'name' => 'Dup',
        'email' => 'PAT.TEACHER@example.org',
        'role' => UserRole::Teacher,
    ], $admin))->toThrow(ValidationException::class);
});

test('teachers and students are forbidden on every user management route', function () {
    $admin = asAdmin();
    $target = app(UserAccountService::class)->createProvisionedUser([
        'name' => 'Target',
        'email' => 'target@example.org',
        'role' => UserRole::Teacher,
        'password' => 'password',
    ], $admin);

    $teacher = asTeacher();
    $this->actingAs($teacher)
        ->get(UserResource::getUrl('index'))
        ->assertForbidden();
    $this->get(UserResource::getUrl('create'))->assertForbidden();
    $this->get(UserResource::getUrl('edit', ['record' => $target]))->assertForbidden();

    Livewire::actingAs($teacher)
        ->test(ListUsers::class)
        ->assertForbidden();

    $student = asStudent();
    $this->actingAs($student)
        ->get(UserResource::getUrl('index'))
        ->assertForbidden();
});

test('provisioned unusable admin does not count as last operational admin', function () {
    $accounts = app(UserAccountService::class);

    $usable = $accounts->createProvisionedUser([
        'name' => 'Usable Admin',
        'email' => 'usable-admin@example.org',
        'role' => UserRole::Admin,
        'password' => 'password',
    ]);
    $provisioned = $accounts->createProvisionedUser([
        'name' => 'Shell Admin',
        'email' => 'shell-admin@example.org',
        'role' => UserRole::Admin,
        'password' => null,
    ]);

    expect($accounts->isOperationalAdmin($usable))->toBeTrue()
        ->and($accounts->isOperationalAdmin($provisioned))->toBeFalse();

    // Usable admin can still be the last operational one despite the shell row.
    expect(fn () => $accounts->deactivate($usable))
        ->toThrow(ValidationException::class);
    expect(fn () => $accounts->changeRole($usable, UserRole::Teacher))
        ->toThrow(ValidationException::class);

    // Shell admin can be demoted freely.
    $accounts->changeRole($provisioned, UserRole::Teacher);
    expect($provisioned->fresh()->role)->toBe(UserRole::Teacher);

    // With two usable admins, one may be demoted or deactivated.
    $second = $accounts->createProvisionedUser([
        'name' => 'Second Admin',
        'email' => 'second-admin@example.org',
        'role' => UserRole::Admin,
        'password' => 'password',
    ]);
    $accounts->changeRole($usable->fresh(), UserRole::Teacher);
    expect($usable->fresh()->role)->toBe(UserRole::Teacher);

    $accounts->deactivate($usable->fresh());
    expect($usable->fresh()->deactivated_at)->not->toBeNull();

    // Second remains the sole operational admin and cannot be removed.
    expect(fn () => $accounts->deactivate($second->fresh()))
        ->toThrow(ValidationException::class);
});

test('deactivating or demoting the sole class teacher is refused and names the class', function () {
    $accounts = app(UserAccountService::class);
    $teacher = $accounts->createProvisionedUser([
        'name' => 'Solo',
        'email' => 'solo-teacher@example.org',
        'role' => UserRole::Teacher,
        'password' => 'password',
    ]);
    $class = app(SchoolClassService::class)->create($teacher, [
        'name' => 'Period Solo',
        'school_year' => '2026-2027',
    ]);

    try {
        $accounts->deactivate($teacher);
        expect(false)->toBeTrue('expected deactivate to fail');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain('Period Solo');
    }

    try {
        $accounts->changeRole($teacher->fresh(), UserRole::Student);
        expect(false)->toBeTrue('expected demote to fail');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain('Period Solo');
    }

    expect(ClassMembership::query()
        ->where('school_class_id', $class->id)
        ->where('user_id', $teacher->id)
        ->whereNull('withdrawn_at')
        ->exists())->toBeTrue();

    $other = $accounts->createProvisionedUser([
        'name' => 'Co-teacher',
        'email' => 'co-teacher@example.org',
        'role' => UserRole::Teacher,
        'password' => 'password',
    ]);
    app(ClassMembershipService::class)->addOrReactivateTeacher($class, $other, $teacher);

    $accounts->changeRole($teacher->fresh(), UserRole::Student);
    expect($teacher->fresh()->role)->toBe(UserRole::Student)
        ->and(ClassMembership::query()
            ->where('school_class_id', $class->id)
            ->where('user_id', $teacher->id)
            ->whereNull('withdrawn_at')
            ->exists())->toBeTrue(); // memberships never silently altered
});

test('deactivation deletes database sessions and middleware rejects stale auth', function () {
    $accounts = app(UserAccountService::class);
    $admin = $accounts->createProvisionedUser([
        'name' => 'Ops Admin',
        'email' => 'ops-admin@example.org',
        'role' => UserRole::Admin,
        'password' => 'password',
    ]);
    $teacher = $accounts->createProvisionedUser([
        'name' => 'Session Teacher',
        'email' => 'session-teacher@example.org',
        'role' => UserRole::Teacher,
        'password' => 'password',
    ]);

    config(['session.driver' => 'database']);

    DB::table('sessions')->insert([
        'id' => 'sess-teacher-1',
        'user_id' => $teacher->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => 'x',
        'last_activity' => time(),
    ]);

    $accounts->deactivate($teacher, $admin);

    expect(DB::table('sessions')->where('user_id', $teacher->id)->count())->toBe(0)
        ->and($teacher->fresh()->remember_token)->toBeNull()
        ->and(UserAccountChange::query()->where('user_id', $teacher->id)->where('action', 'deactivated')->count())->toBe(1);

    $this->actingAs($teacher->fresh())
        ->get(route('home'))
        ->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse();

    $accounts->reactivate($teacher->fresh(), $admin);
    // Reactivation does not restore the deleted session row.
    expect(DB::table('sessions')->where('user_id', $teacher->id)->count())->toBe(0)
        ->and(UserAccountChange::query()->where('user_id', $teacher->id)->where('action', 'reactivated')->count())->toBe(1);
});

test('passwordless account fails local login with the same message as unknown email', function () {
    $accounts = app(UserAccountService::class);
    $accounts->createProvisionedUser([
        'name' => 'Awaiting',
        'email' => 'awaiting@example.org',
        'role' => UserRole::Teacher,
        'password' => null,
    ]);

    $unknown = $this->from(route('login'))->post(route('login'), [
        'email' => 'nobody-here@example.org',
        'password' => 'whatever',
    ]);
    $unknown->assertSessionHasErrors('email');
    $unknownMessage = session('errors')->get('email')[0];

    $awaiting = $this->from(route('login'))->post(route('login'), [
        'email' => 'awaiting@example.org',
        'password' => 'whatever',
    ]);
    $awaiting->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toBe($unknownMessage)
        ->and($unknownMessage)->toBe(__('auth.failed'));

    $html = $awaiting->getContent();
    // Public form must not leak provisioning state.
    expect(str_contains(strtolower($html), 'awaiting google'))->toBeFalse()
        ->and(str_contains(strtolower($html), 'google sign-in'))->toBeFalse();
});

test('create-staff-user works where UserSeeder refuses; never overwrites; force is required non-interactively', function () {
    $this->app->detectEnvironment(fn () => 'production');

    expect(fn () => (new UserSeeder)->run())
        ->toThrow(RuntimeException::class);

    $exit = Artisan::call('teched:create-staff-user', [
        '--name' => 'Prod Admin',
        '--email' => 'Prod.Admin@Example.org',
        '--role' => 'admin',
        '--password' => 'prod-secret',
        '--force' => true,
    ]);
    expect($exit)->toBe(0);

    $user = User::query()->where('email', 'prod.admin@example.org')->first();
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::Admin)
        ->and(Hash::check('prod-secret', $user->password))->toBeTrue();

    $again = Artisan::call('teched:create-staff-user', [
        '--name' => 'Prod Admin 2',
        '--email' => 'prod.admin@example.org',
        '--role' => 'admin',
        '--password' => 'other',
        '--force' => true,
    ]);
    expect($again)->not->toBe(0)
        ->and(User::query()->where('email', 'prod.admin@example.org')->count())->toBe(1);

    $this->artisan('teched:create-staff-user', [
        '--name' => 'Another',
        '--email' => 'another@example.org',
        '--role' => 'teacher',
    ])->assertFailed();
});

test('form boundaries and audit rows; immutable audit refuses update and delete', function () {
    $admin = asAdmin();
    $accounts = app(UserAccountService::class);

    $user = $accounts->createProvisionedUser([
        'name' => 'Boundary',
        'email' => 'boundary@example.org',
        'role' => UserRole::Teacher,
        'password' => 'password',
    ], $admin);

    // Crafted mass-assignment cannot set privilege / identity fields.
    $user->update([
        'role' => 'admin',
        'google_id' => 'evil',
        'deactivated_at' => now(),
        'name' => 'Boundary Updated',
    ]);
    $user->refresh();
    expect($user->name)->toBe('Boundary Updated')
        ->and($user->role)->toBe(UserRole::Teacher)
        ->and($user->google_id)->toBeNull()
        ->and($user->deactivated_at)->toBeNull();

    $secondAdmin = $accounts->createProvisionedUser([
        'name' => 'Other Ops',
        'email' => 'other-ops@example.org',
        'role' => UserRole::Admin,
        'password' => 'password',
    ], $admin);

    $accounts->changeRole($user->fresh(), UserRole::Admin, $admin);
    $accounts->changeEmail($user->fresh(), 'Boundary.New@Example.org', $admin);
    $accounts->deactivate($user->fresh(), $admin);
    $accounts->reactivate($user->fresh(), $admin);

    foreach (['created', 'role_changed', 'email_changed', 'deactivated', 'reactivated'] as $action) {
        $row = UserAccountChange::query()
            ->where('user_id', $user->id)
            ->where('action', $action)
            ->first();
        expect($row)->not->toBeNull("missing audit {$action}")
            ->and($row->changed_by_user_id)->toBe($admin->id);

        $detail = $row->detail ?? [];
        expect($detail)->not->toHaveKey('password')
            ->and($detail)->not->toHaveKey('access_token')
            ->and($detail)->not->toHaveKey('id_token')
            ->and(json_encode($detail))->not->toContain('secret')
            ->and(json_encode($detail))->not->toContain('prod-secret');
    }

    $emailRow = UserAccountChange::query()
        ->where('user_id', $user->id)
        ->where('action', 'email_changed')
        ->first();
    expect($emailRow->detail['old_email'])->toBe('boundary@example.org')
        ->and($emailRow->detail['new_email'])->toBe('boundary.new@example.org');

    expect(fn () => $emailRow->update(['action' => 'tampered']))
        ->toThrow(ImmutableUserAccountChangeException::class);
    expect(fn () => $emailRow->delete())
        ->toThrow(ImmutableUserAccountChangeException::class);

    Livewire::actingAs($admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Livewire User',
            'email' => 'Livewire.User@Example.org',
            'role' => UserRole::Teacher->value,
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'livewire.user@example.org')->first();
    expect($created)->not->toBeNull()
        ->and($created->google_id)->toBeNull()
        ->and($created->deactivated_at)->toBeNull()
        ->and($created->role)->toBe(UserRole::Teacher);

    // mutateFormDataBeforeCreate drops crafted identity fields.
    $page = new CreateUser;
    $filtered = (new \ReflectionClass($page))
        ->getMethod('mutateFormDataBeforeCreate');
    $filtered->setAccessible(true);
    $stripped = $filtered->invoke($page, [
        'name' => 'X',
        'email' => 'x@example.org',
        'role' => 'teacher',
        'google_id' => 'nope',
        'deactivated_at' => now()->toISOString(),
        'remember_token' => 'tok',
    ]);
    expect($stripped)->not->toHaveKey('google_id')
        ->and($stripped)->not->toHaveKey('deactivated_at')
        ->and($stripped)->not->toHaveKey('remember_token');

    unset($secondAdmin);
});

test('linkGoogleIdentity enforces the Phase 6 contract guards', function () {
    config(['google.allowed_hosted_domains' => ['school.edu']]);
    $accounts = app(UserAccountService::class);

    $user = $accounts->createProvisionedUser([
        'name' => 'Link Me',
        'email' => 'link.me@school.edu',
        'role' => UserRole::Teacher,
        'password' => null,
    ]);

    expect(fn () => $accounts->linkGoogleIdentity($user, [
        'google_id' => 'gid-1',
        'email' => 'link.me@school.edu',
        'email_verified' => false,
        'hosted_domain' => 'school.edu',
    ]))->toThrow(ValidationException::class);

    expect(fn () => $accounts->linkGoogleIdentity($user, [
        'google_id' => 'gid-1',
        'email' => 'link.me@school.edu',
        'email_verified' => true,
        'hosted_domain' => 'gmail.com',
    ]))->toThrow(ValidationException::class);

    expect(fn () => $accounts->linkGoogleIdentity($user, [
        'google_id' => 'gid-1',
        'email' => 'other@school.edu',
        'email_verified' => true,
        'hosted_domain' => 'school.edu',
    ]))->toThrow(ValidationException::class);

    $linked = $accounts->linkGoogleIdentity($user->fresh(), [
        'google_id' => 'gid-1',
        'email' => 'Link.Me@School.EDU',
        'email_verified' => true,
        'hosted_domain' => 'school.edu',
    ]);

    expect($linked->google_id)->toBe('gid-1')
        ->and(UserAccountChange::query()->where('user_id', $user->id)->where('action', 'google_linked')->count())->toBe(1);

    $audit = UserAccountChange::query()->where('action', 'google_linked')->first();
    expect($audit->changed_by_user_id)->toBe($user->id)
        ->and($audit->detail['google_id_added'])->toBeTrue()
        ->and($audit->detail)->not->toHaveKey('password');

    expect(fn () => $accounts->linkGoogleIdentity($linked->fresh(), [
        'google_id' => 'gid-2',
        'email' => 'link.me@school.edu',
        'email_verified' => true,
        'hosted_domain' => 'school.edu',
    ]))->toThrow(ValidationException::class);
});

test('admin filament user pages render', function () {
    $admin = asAdmin();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertSuccessful();

    Livewire::actingAs($admin)
        ->test(CreateUser::class)
        ->assertSuccessful();
});
