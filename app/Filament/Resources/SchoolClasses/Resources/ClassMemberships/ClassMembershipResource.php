<?php

namespace App\Filament\Resources\SchoolClasses\Resources\ClassMemberships;

use App\Filament\Resources\SchoolClasses\Resources\ClassMemberships\Pages\EditClassMembership;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\ClassMembership;
use App\Models\SchoolClass;
use BackedEnum;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Nested under a class so membership URLs are scoped. Mismatch → 404 before
 * authorization (same rule as lesson pages).
 */
class ClassMembershipResource extends Resource
{
    protected static ?string $model = ClassMembership::class;

    protected static ?string $parentResource = SchoolClassResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'members';

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return SchoolClassResource::asParent()
            ->relationship('memberships')
            ->inverseRelationship('schoolClass');
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditClassMembership::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('viewAny', SchoolClass::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        /** @var ClassMembership $record */
        $class = $record->schoolClass ?? SchoolClass::query()->find($record->school_class_id);

        return $class !== null && Gate::allows('manage', $class);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
