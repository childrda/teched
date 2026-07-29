<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

test('role casts to UserRole', function () {
    $user = User::factory()->create();
    $user->forceFill(['role' => UserRole::Teacher])->save();

    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->role)->toBe(UserRole::Teacher)
        ->and($fresh->isTeacher())->toBeTrue()
        ->and($fresh->isStudent())->toBeFalse()
        ->and($fresh->isAdmin())->toBeFalse()
        ->and($fresh->getRawOriginal('role'))->toBe('teacher');
});

test('google_id enforces uniqueness', function () {
    $first = User::factory()->create();
    $first->forceFill(['google_id' => 'google-abc'])->save();

    $second = User::factory()->create();

    expect(fn () => $second->forceFill(['google_id' => 'google-abc'])->save())
        ->toThrow(QueryException::class);
});

test('mass assignment cannot set role or google_id on create or update', function () {
    $created = User::query()->create([
        'name' => 'Mass Create',
        'email' => 'mass-create@teched.test',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'google_id' => 'should-not-stick',
    ]);

    $created->refresh();

    expect($created->role)->toBe(UserRole::Student)
        ->and($created->google_id)->toBeNull();

    $created->update([
        'role' => 'admin',
        'google_id' => 'still-should-not-stick',
        'name' => 'Mass Updated',
    ]);

    $created->refresh();

    expect($created->name)->toBe('Mass Updated')
        ->and($created->role)->toBe(UserRole::Student)
        ->and($created->google_id)->toBeNull();
});

test('creating a user without assigning role stores student via the column default', function () {
    $user = User::query()->create([
        'name' => 'Default Role',
        'email' => 'default-role@teched.test',
        'password' => Hash::make('password'),
    ]);

    $user->refresh();

    expect($user->getRawOriginal('role'))->toBe('student')
        ->and($user->role)->toBe(UserRole::Student)
        ->and($user->isStudent())->toBeTrue();
});
