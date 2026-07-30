<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Exceptions\AuthoringValidationException;
use App\Filament\Resources\Lessons\LessonResource;
use App\Services\LessonAuthoringService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateLesson extends CreateRecord
{
    protected static string $resource = LessonResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(LessonAuthoringService::class)->create($data, Auth::user());
        } catch (AuthoringValidationException $e) {
            Notification::make()
                ->title('Could not save draft')
                ->body(implode("\n", $e->errors))
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
