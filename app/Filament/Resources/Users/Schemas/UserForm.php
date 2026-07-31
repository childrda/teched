<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserAccountService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->helperText('Stored lowercase. Unlinked: changing email changes who can claim the account. Google-linked: email is display-only; google_id signs in.'),
                        Select::make('role')
                            ->options([
                                UserRole::Teacher->value => 'Teacher',
                                UserRole::Admin->value => 'Admin',
                                UserRole::Student->value => 'Student (unusual — normally arrives from Classroom import)',
                            ])
                            ->required()
                            ->native(false),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visibleOn('create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Leave blank to create a Google-provisioned account (no local password). Blank is intentional — not an omission.'),
                        Placeholder::make('sign_in_status')
                            ->label('Sign-in status')
                            ->visibleOn('edit')
                            ->content(function (?User $record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                if ($record->deactivated_at !== null) {
                                    return 'Deactivated';
                                }

                                if (app(UserAccountService::class)->awaitingGoogleSignIn($record)) {
                                    return 'Awaiting Google sign-in';
                                }

                                if ($record->google_id !== null && $record->password !== null) {
                                    return 'Google + local password';
                                }

                                if ($record->google_id !== null) {
                                    return 'Google';
                                }

                                return 'Local password';
                            }),
                    ])
                    ->columns(2),
            ]);
    }
}
