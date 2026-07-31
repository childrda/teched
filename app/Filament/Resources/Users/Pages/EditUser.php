<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserAccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Never expose secrets or identity-binding fields as editable state.
        unset($data['password'], $data['google_id'], $data['remember_token']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset(
            $data['id'],
            $data['google_id'],
            $data['deactivated_at'],
            $data['email_verified_at'],
            $data['remember_token'],
            $data['password'],
            $data['sign_in_status'],
        );

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $accounts = app(UserAccountService::class);
        $actor = Auth::user();

        try {
            $accounts->updateName($record, (string) ($data['name'] ?? $record->name), $actor);

            if (array_key_exists('email', $data)) {
                $accounts->changeEmail($record->fresh(), (string) $data['email'], $actor);
            }

            if (array_key_exists('role', $data)) {
                $accounts->changeRole($record->fresh(), $data['role'], $actor);
            }

            return $record->fresh();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not save user')
                ->body(collect($e->errors())->flatten()->implode("\n"))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setPassword')
                ->label('Set password')
                ->form([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(8)
                        ->helperText('Sets a local password. Leaves Google link intact if present. Revokes existing sessions.'),
                ])
                ->visible(fn (): bool => Auth::user()?->isAdmin() ?? false)
                ->action(function (array $data): void {
                    try {
                        app(UserAccountService::class)->setPassword(
                            $this->getRecord(),
                            (string) $data['password'],
                            Auth::user(),
                        );
                        Notification::make()->title('Password set')->success()->send();
                        $this->fillForm();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not set password')
                            ->body(collect($e->errors())->flatten()->implode("\n"))
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('deactivate')
                ->label('Deactivate')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Deactivate user')
                ->modalDescription('Ends current sessions immediately. History and memberships are kept. The user cannot sign in until reactivated.')
                ->visible(fn (): bool => Auth::user()?->isAdmin()
                    && $this->getRecord()->deactivated_at === null)
                ->action(function (): void {
                    try {
                        app(UserAccountService::class)->deactivate($this->getRecord(), Auth::user());
                        Notification::make()->title('User deactivated')->success()->send();
                        $this->fillForm();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not deactivate')
                            ->body(collect($e->errors())->flatten()->implode("\n"))
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            Action::make('reactivate')
                ->label('Reactivate')
                ->requiresConfirmation()
                ->visible(fn (): bool => Auth::user()?->isAdmin()
                    && $this->getRecord()->deactivated_at !== null)
                ->action(function (): void {
                    try {
                        app(UserAccountService::class)->reactivate($this->getRecord(), Auth::user());
                        Notification::make()->title('User reactivated')->success()->send();
                        $this->fillForm();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not reactivate')
                            ->body(collect($e->errors())->flatten()->implode("\n"))
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Auth::user()?->isAdmin() ?? false, 403);
    }
}
