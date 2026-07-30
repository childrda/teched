<?php

namespace App\Filament\Resources\SchoolClasses\RelationManagers;

use App\Enums\ClassRole;
use App\Enums\UserRole;
use App\Models\ClassMembership;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ClassMembershipService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Roster';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user')->orderByRaw('CASE WHEN withdrawn_at IS NULL THEN 0 ELSE 1 END')->orderBy('id'))
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable(),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ClassRole ? ucfirst($state->value) : (string) $state),
                TextColumn::make('joined_at')->dateTime()->placeholder('—'),
                TextColumn::make('withdrawn_at')
                    ->label('Status')
                    ->formatStateUsing(fn ($state, ClassMembership $record) => $record->isActive() ? 'Active' : 'Withdrawn')
                    ->badge()
                    ->color(fn (ClassMembership $record) => $record->isActive() ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('roster')
                    ->label('Roster')
                    ->options([
                        'active' => 'Active',
                        'withdrawn' => 'Withdrawn',
                    ])
                    ->default('active')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? 'active') {
                            'withdrawn' => $query->whereNotNull('withdrawn_at'),
                            'all' => $query,
                            default => $query->whereNull('withdrawn_at'),
                        };
                    }),
            ])
            ->headerActions([
                Action::make('addStudent')
                    ->label('Add student')
                    ->visible(fn (): bool => Gate::allows('manage', $this->getOwnerRecord()))
                    ->form([
                        $this->directoryUserSelect(ClassRole::Student),
                    ])
                    ->action(function (array $data): void {
                        $this->runMembershipMutation(function () use ($data) {
                            /** @var SchoolClass $class */
                            $class = $this->getOwnerRecord();
                            $user = User::query()->findOrFail($data['user_id']);

                            app(ClassMembershipService::class)->addOrReactivateStudent(
                                $class,
                                $user,
                                Auth::user()
                            );
                        });
                    }),
                Action::make('addTeacher')
                    ->label('Add teacher')
                    ->visible(fn (): bool => Gate::allows('manage', $this->getOwnerRecord()))
                    ->form([
                        $this->directoryUserSelect(ClassRole::Teacher),
                    ])
                    ->action(function (array $data): void {
                        $this->runMembershipMutation(function () use ($data) {
                            /** @var SchoolClass $class */
                            $class = $this->getOwnerRecord();
                            $user = User::query()->findOrFail($data['user_id']);

                            app(ClassMembershipService::class)->addOrReactivateTeacher(
                                $class,
                                $user,
                                Auth::user()
                            );
                        });
                    }),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->visible(fn (ClassMembership $record): bool => Gate::allows('manage', $this->getOwnerRecord()) && $record->isActive())
                    ->form([
                        Select::make('role')
                            ->options([
                                ClassRole::Student->value => 'Student',
                                ClassRole::Teacher->value => 'Teacher',
                            ])
                            ->required(),
                    ])
                    ->fillForm(fn (ClassMembership $record): array => [
                        'role' => $record->role->value,
                    ])
                    ->action(function (ClassMembership $record, array $data): void {
                        $this->runMembershipMutation(function () use ($record, $data) {
                            app(ClassMembershipService::class)->changeRole(
                                $record,
                                ClassRole::from($data['role']),
                                Auth::user()
                            );
                        });
                    }),
                Action::make('withdraw')
                    ->label('Withdraw')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ClassMembership $record): bool => Gate::allows('manage', $this->getOwnerRecord()) && $record->isActive())
                    ->action(function (ClassMembership $record): void {
                        $this->runMembershipMutation(function () use ($record) {
                            app(ClassMembershipService::class)->withdraw($record, Auth::user());
                        });
                    }),
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->visible(fn (ClassMembership $record): bool => Gate::allows('manage', $this->getOwnerRecord()) && ! $record->isActive())
                    ->action(function (ClassMembership $record): void {
                        $this->runMembershipMutation(function () use ($record) {
                            $service = app(ClassMembershipService::class);
                            $user = $record->user;
                            $class = $this->getOwnerRecord();

                            if ($record->role === ClassRole::Teacher) {
                                $service->addOrReactivateTeacher($class, $user, Auth::user());
                            } else {
                                $service->addOrReactivateStudent($class, $user, Auth::user());
                            }
                        });
                    }),
            ]);
    }

    /**
     * Directory-exposure surface: first place one user can enumerate others.
     * Restrict by plausible role, require a typed search (min length 2), and
     * return name + email only — never list every district user on page load.
     */
    private function directoryUserSelect(ClassRole $forRole): Select
    {
        $roles = $forRole === ClassRole::Teacher
            ? [UserRole::Teacher->value, UserRole::Admin->value]
            : [UserRole::Student->value];

        return Select::make('user_id')
            ->label('User')
            ->searchable()
            ->required()
            ->getSearchResultsUsing(function (string $search) use ($roles): array {
                $search = trim($search);

                // Directory-exposure surface: refuse empty / short queries.
                if (mb_strlen($search) < 2) {
                    return [];
                }

                return User::query()
                    ->whereIn('role', $roles)
                    ->where(function (Builder $query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orderBy('name')
                    ->limit(20)
                    ->get(['id', 'name', 'email'])
                    ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"])
                    ->all();
            })
            ->getOptionLabelUsing(function ($value): ?string {
                $user = User::query()->find($value);

                return $user ? "{$user->name} ({$user->email})" : null;
            })
            ->helperText('Type at least 2 characters to search by name or email.');
    }

    private function runMembershipMutation(callable $callback): void
    {
        try {
            $callback();
            Notification::make()->title('Roster updated')->success()->send();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not update roster')
                ->body(collect($e->errors())->flatten()->implode("\n"))
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
