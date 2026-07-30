<?php

namespace App\Filament\Resources\SchoolClasses\Pages;

use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\SchoolClass;
use App\Services\SchoolClassService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSchoolClass extends EditRecord
{
    protected static string $resource = SchoolClassResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SchoolClass $record */
        try {
            return app(SchoolClassService::class)->update($record, [
                'name' => $data['name'] ?? $record->name,
                'school_year' => $data['school_year'] ?? $record->school_year,
                'active' => $data['active'] ?? $record->active,
            ]);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not save class')
                ->body(collect($e->errors())->flatten()->implode("\n"))
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
