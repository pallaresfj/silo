<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentCategoryResource\Pages;
use App\Models\DocumentCategory;
use App\Support\GoogleDriveHelper;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class DocumentCategoryResource extends Resource
{
    protected static ?string $model = DocumentCategory::class;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-tag';
    }

    public static function getNavigationLabel(): string
    {
        return 'Categorías';
    }

    public static function getModelLabel(): string
    {
        return 'Categoría';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Categorías';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Gestión Documental';
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos de la categoría')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(100)
                            ->columnSpanFull(),

                        TextInput::make('slug')
                            ->label('Slug')
                            ->maxLength(100)
                            ->helperText('Se genera automáticamente si se deja vacío.')
                            ->unique(ignoreRecord: true),

                        ColorPicker::make('color')
                            ->label('Color del badge')
                            ->hex()
                            ->default(DocumentCategory::DEFAULT_COLOR)
                            ->helperText('Selecciona cualquier color para identificar esta categoría.')
                            ->required(),

                        Toggle::make('is_system')
                            ->label('Categoría del sistema')
                            ->helperText('Las categorías del sistema no se pueden eliminar.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),

                ColorColumn::make('color')
                    ->label('Color')
                    ->state(fn (DocumentCategory $record): string => DocumentCategory::normalizeColor($record->color)),

                IconColumn::make('is_system')
                    ->label('Sistema')
                    ->boolean(),

                TextColumn::make('documents_count')
                    ->label('Documentos')
                    ->counts('documents')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->actions([
                EditAction::make()
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Editar'),

                DeleteAction::make()
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Borrar')
                    ->before(function (DocumentCategory $record): void {
                        static::guardDeletion($record);
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentCategories::route('/'),
            'create' => Pages\CreateDocumentCategory::route('/create'),
            'edit' => Pages\EditDocumentCategory::route('/{record}/edit'),
        ];
    }

    public static function guardDeletion(DocumentCategory $record): void
    {
        if ($record->is_system) {
            Notification::make()
                ->danger()
                ->title('No se puede eliminar la categoría')
                ->body('Esta categoría es del sistema.')
                ->persistent()
                ->send();

            throw new Halt;
        }

        if ($record->documents()->exists()) {
            Notification::make()
                ->danger()
                ->title('No se puede eliminar la categoría')
                ->body('Existen documentos vinculados a esta categoría.')
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    public static function syncRenamedCategoryInDrive(string $oldSlug, string $newSlug): int
    {
        return GoogleDriveHelper::renameCategoryFoldersAcrossYears($oldSlug, $newSlug);
    }
}
