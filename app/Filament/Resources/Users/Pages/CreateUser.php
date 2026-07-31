<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserAccountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Strip identity / audit fields — route and service own those.
        unset(
            $data['id'],
            $data['google_id'],
            $data['deactivated_at'],
            $data['email_verified_at'],
            $data['remember_token'],
            $data['sign_in_status'],
        );

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(UserAccountService::class)->createProvisionedUser([
                'name' => $data['name'] ?? '',
                'email' => $data['email'] ?? '',
                'role' => $data['role'] ?? null,
                'password' => $data['password'] ?? null,
            ], Auth::user());
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not create user')
                ->body(collect($e->errors())->flatten()->implode("\n"))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
