<?php

namespace App\Filament\Resources\SchoolClasses\Resources\ClassMemberships\Pages;

use App\Filament\Resources\SchoolClasses\Resources\ClassMemberships\ClassMembershipResource;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\ClassMembership;
use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Membership mutations go through ClassMembershipService on the relation
 * manager. This page exists so class/membership URLs enforce scoped
 * belonging — mismatch is 404 before authorization.
 */
class EditClassMembership extends EditRecord
{
    protected static string $resource = ClassMembershipResource::class;

    /**
     * Resolve parent without teacher SQL scope so a foreign class yields 403
     * (policy) rather than 404 (invisible record).
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function resolveParentRecord(array $parameters): Model
    {
        $key = $parameters['school_class'] ?? null;
        $parent = SchoolClass::query()->find($key);

        if ($parent === null) {
            throw (new ModelNotFoundException)->setModel(SchoolClass::class, [$key]);
        }

        return $parent;
    }

    protected function authorizeParentRecordAccess(): void
    {
        abort_unless(Gate::allows('manage', $this->getParentRecord()), 403);
    }

    public function mount(int|string $record, ?Model $parentRecord = null): void
    {
        if ($parentRecord instanceof SchoolClass) {
            $this->parentRecord = $parentRecord;
            $this->authorizeParentRecordAccess();
        } else {
            $this->mountParentRecord();
        }

        parent::mount($record);

        /** @var ClassMembership $membership */
        $membership = $this->getRecord();
        /** @var SchoolClass $class */
        $class = $this->getParentRecord();

        if ((int) $membership->school_class_id !== (int) $class->getKey()) {
            abort(404);
        }
    }

    public function form(Schema $schema): Schema
    {
        /** @var ClassMembership $membership */
        $membership = $this->getRecord();
        $membership->loadMissing('user');

        return $schema->components([
            Placeholder::make('user_name')
                ->label('Name')
                ->content(fn () => $membership->user?->name),
            Placeholder::make('user_email')
                ->label('Email')
                ->content(fn () => $membership->user?->email),
            Placeholder::make('role')
                ->label('Role')
                ->content(fn () => $membership->role?->value),
            Placeholder::make('status')
                ->label('Status')
                ->content(fn () => $membership->isActive() ? 'Active' : 'Withdrawn'),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Payload boundary: never accept user role / email / class id here.
        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to class')
                ->url(fn (): string => SchoolClassResource::getUrl('edit', ['record' => $this->getParentRecord()])),
        ];
    }
}
