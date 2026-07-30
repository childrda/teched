<?php

namespace App\Filament\Resources\Lessons\Resources\LessonPages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Resources\LessonPages\Pages\EditLessonPage;
use App\Filament\Resources\Lessons\Resources\LessonPages\Schemas\LessonPageForm;
use App\Models\Lesson;
use App\Models\LessonPage;
use BackedEnum;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class LessonPageResource extends Resource
{
    protected static ?string $model = LessonPage::class;

    protected static ?string $parentResource = LessonResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'pages';

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return LessonResource::asParent()
            ->relationship('pages')
            ->inverseRelationship('lesson');
    }

    public static function form(Schema $schema): Schema
    {
        return LessonPageForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditLessonPage::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('viewAny', Lesson::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('create', Lesson::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        /** @var LessonPage $record */
        $lesson = $record->lesson ?? Lesson::query()->find($record->lesson_id);

        return $lesson !== null && Gate::allows('update', $lesson);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function canView(Model $record): bool
    {
        /** @var LessonPage $record */
        $lesson = $record->lesson ?? Lesson::query()->find($record->lesson_id);

        return $lesson !== null && Gate::allows('view', $lesson);
    }
}
