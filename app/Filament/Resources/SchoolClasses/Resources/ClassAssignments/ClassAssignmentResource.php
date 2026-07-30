<?php

namespace App\Filament\Resources\SchoolClasses\Resources\ClassAssignments;

use App\Filament\Resources\SchoolClasses\Resources\ClassAssignments\Pages\EditClassAssignment;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\LessonAssignment;
use App\Models\SchoolClass;
use BackedEnum;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Nested under a class so assignment URLs are scoped. Mismatch → 404 before
 * authorization.
 */
class ClassAssignmentResource extends Resource
{
    protected static ?string $model = LessonAssignment::class;

    protected static ?string $parentResource = SchoolClassResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'assignments';

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return SchoolClassResource::asParent()
            ->relationship('assignments')
            ->inverseRelationship('schoolClass');
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditClassAssignment::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('viewAny', SchoolClass::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('update', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->can('delete', $record) ?? false;
    }

    public static function canView(Model $record): bool
    {
        /** @var LessonAssignment $record */
        $class = $record->schoolClass ?? SchoolClass::query()->find($record->school_class_id);

        return $class !== null && Gate::allows('view', $class);
    }
}
