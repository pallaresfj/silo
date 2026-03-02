<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EntityResource\Pages;
use App\Models\Document;
use App\Models\Entity;
use App\Support\Drive\DocumentDriveDestination;
use App\Support\GoogleDriveHelper;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class EntityResource extends Resource
{
    protected static ?string $model = Entity::class;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-building-office-2';
    }

    public static function getNavigationLabel(): string
    {
        return 'Entidades';
    }

    public static function getModelLabel(): string
    {
        return 'Entidad';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Entidades';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Gestión Documental';
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos de la entidad')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('type')
                            ->label('Tipo')
                            ->required()
                            ->options([
                                'Interna' => 'Interna',
                                'Externa' => 'Externa',
                            ]),
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

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),

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
                    ->before(function (Entity $record): void {
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
            'index' => Pages\ListEntities::route('/'),
            'create' => Pages\CreateEntity::route('/create'),
            'edit' => Pages\EditEntity::route('/{record}/edit'),
        ];
    }

    public static function guardDeletion(Entity $record): void
    {
        if ($record->documents()->exists()) {
            Notification::make()
                ->danger()
                ->title('No se puede eliminar la entidad')
                ->body('Existen documentos vinculados a esta entidad.')
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    public static function syncRenamedEntityInDrive(Entity $entity, string $newName): int
    {
        $documents = Document::withoutGlobalScopes()
            ->with(['category:id,slug'])
            ->where('entity_id', $entity->id)
            ->whereNotNull('gdrive_id')
            ->get();

        $synced = 0;

        foreach ($documents as $document) {
            $targetFolderId = GoogleDriveHelper::ensureDocumentFolderForDestination(
                new DocumentDriveDestination(
                    storageScope: $document->storage_scope ?: Document::STORAGE_SCOPE_YEARLY,
                    year: (int) ($document->year ?? now()->year),
                    categorySlug: GoogleDriveHelper::normalizeCategorySlug($document->category?->slug),
                    entityFolder: $newName,
                )
            );

            $result = GoogleDriveHelper::moveFileToFolder((string) $document->gdrive_id, $targetFolderId);

            if ($result === 'moved' || $result === 'already') {
                $synced++;
            }
        }

        return $synced;
    }
}
