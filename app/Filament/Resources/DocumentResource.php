<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\DocumentCategory;
use App\Models\Document;
use App\Support\GoogleDriveHelper;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Throwable;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-document-text';
    }

    public static function getNavigationLabel(): string
    {
        return 'Documentos';
    }

    public static function getModelLabel(): string
    {
        return 'Documento';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Documentos';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Gestión Documental';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // ── File Upload Section ──
                Section::make('Archivo')
                    ->icon('heroicon-o-paper-clip')
                    ->schema([
                        Radio::make('creation_mode')
                            ->label('Como crear el archivo')
                            ->options([
                                'upload' => 'Subir archivo',
                                'drive_native' => 'Crear documento en Drive',
                            ])
                            ->default('upload')
                            ->live()
                            ->columnSpanFull(),

                        Select::make('drive_native_type')
                            ->label('Tipo de documento en Drive')
                            ->options([
                                'document' => 'Google Docs (texto)',
                                'spreadsheet' => 'Google Sheets (hoja de calculo)',
                                'presentation' => 'Google Slides (presentacion)',
                            ])
                            ->default('document')
                            ->required(fn (Get $get): bool => ($get('creation_mode') ?? 'upload') === 'drive_native')
                            ->visible(fn (Get $get): bool => ($get('creation_mode') ?? 'upload') === 'drive_native')
                            ->helperText('Se creara un archivo nativo en la carpeta de Drive del documento.')
                            ->columnSpanFull(),

                        FileUpload::make('attachment')
                            ->label('Archivo')
                            ->disk('local') // temp upload to local; we move to Drive on save
                            ->directory('documents-temp')
                            ->preserveFilenames()
                            ->maxSize(25600) // 25 MB
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'image/jpeg',
                                'image/png',
                            ])
                            ->visible(fn (Get $get): bool => ($get('creation_mode') ?? 'upload') === 'upload')
                            ->helperText('Opcional. Si no adjuntas archivo, se creara solo el registro.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                // ── Metadata Section ──
                Section::make('Metadatos')
                    ->icon('heroicon-o-tag')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Título')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('category_id')
                            ->label('Categoría')
                            ->relationship('category', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('slug')
                                    ->label('Slug')
                                    ->maxLength(100)
                                    ->helperText('Se genera automáticamente si se deja vacío'),
                                ColorPicker::make('color')
                                    ->label('Color del badge')
                                    ->hex()
                                    ->default(DocumentCategory::DEFAULT_COLOR)
                                    ->required(),
                            ]),

                        Select::make('entity_id')
                            ->label('Entidad')
                            ->relationship('entity', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('type')
                                    ->label('Tipo')
                                    ->options([
                                        'Interna' => 'Interna',
                                        'Externa' => 'Externa',
                                    ])
                                    ->required(),
                            ]),

                        TextInput::make('year')
                            ->label('Año')
                            ->numeric()
                            ->default(now()->year)
                            ->required()
                            ->minValue(2010)
                            ->maxValue(2099),

                        Select::make('status')
                            ->label('Estado')
                            ->options([
                                'Borrador' => 'Borrador',
                                'Publicado' => 'Publicado',
                                'Archivado' => 'Archivado',
                                'Pendiente_OCR' => 'Pendiente OCR',
                                'Importado_Sin_Clasificar' => 'Importado Sin Clasificar',
                            ])
                            ->default('Borrador')
                            ->required(),

                        TagsInput::make('metadata.tags')
                            ->label('Etiquetas')
                            ->placeholder('Agregar etiqueta...')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')
                    ->label('Año')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('title')
                    ->label('Título')
                    ->weight(FontWeight::Bold)
                    ->icon(fn (Document $record): string => static::resolveDocumentTypeIcon($record->file_name))
                    ->iconColor(fn (Document $record): string => static::resolveDocumentTypeIconColor($record->file_name))
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(
                        fn (Document $record): string => sprintf(
                            '%s (%s)',
                            $record->title,
                            static::resolveDocumentTypeLabel($record->file_name)
                        )
                    ),

                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->badge()
                    ->color(fn (Document $record): array => Color::hex(
                        DocumentCategory::normalizeColor($record->category?->color)
                    )),

                TextColumn::make('entity.name')
                    ->label('Entidad')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'Borrador' => 'warning',
                        'Publicado' => 'success',
                        'Archivado' => 'gray',
                        'Pendiente_OCR' => 'info',
                        'Importado_Sin_Clasificar' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordUrl(
                fn (Document $record): ?string => filled($record->gdrive_url) ? $record->gdrive_url : null,
                shouldOpenInNewTab: true
            )
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('year')
                    ->label('Año')
                    ->options(
                        fn() => Document::withoutGlobalScopes()
                            ->select('year')
                            ->distinct()
                            ->orderBy('year', 'desc')
                            ->pluck('year', 'year')
                            ->toArray()
                    ),

                SelectFilter::make('category')
                    ->label('Categoría')
                    ->relationship('category', 'name'),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'Borrador' => 'Borrador',
                        'Publicado' => 'Publicado',
                        'Archivado' => 'Archivado',
                        'Pendiente_OCR' => 'Pendiente OCR',
                        'Importado_Sin_Clasificar' => 'Importado Sin Clasificar',
                    ]),

                TrashedFilter::make(),
            ])
            ->actions([
                Action::make('preview')
                    ->label('Vista Previa')
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Vista previa')
                    ->modalHeading(fn($record): string => $record->title)
                    ->modalContent(fn($record) => view('filament.resources.document-resource.preview', [
                        'url' => $record->gdrive_url,
                    ]))
                    ->modalWidth('7xl')
                    ->visible(fn($record): bool => !empty($record->gdrive_url)),

                EditAction::make()
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Editar'),

                DeleteAction::make()
                    ->label('Archivar')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Archivar')
                    ->modalHeading('Archivar documento')
                    ->modalDescription('El documento se ocultará de la lista activa, pero el archivo seguirá disponible en Google Drive.')
                    ->modalSubmitActionLabel('Archivar')
                    ->successNotificationTitle('Documento archivado'),

                ForceDeleteAction::make()
                    ->label('Eliminar definitivamente')
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Eliminar definitivamente')
                    ->modalHeading('Eliminar documento definitivamente')
                    ->modalDescription('Esta acción eliminará el registro y el archivo en Google Drive. No se puede deshacer.')
                    ->modalSubmitActionLabel('Eliminar definitivamente')
                    ->action(function (ForceDeleteAction $action, Document $record): void {
                        try {
                            static::deleteFromGoogleDrive($record);
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('No se pudo eliminar definitivamente')
                                ->body('No pudimos eliminar el archivo en Google Drive. Intenta nuevamente.')
                                ->persistent()
                                ->send();

                            throw new Halt;
                        }

                        $result = $action->process(static fn (Document $record): ?bool => $record->forceDelete());

                        if (! $result) {
                            $action->failure();

                            return;
                        }

                        $action->success();
                    }),
                RestoreAction::make()
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Restaurar'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Archivar seleccionados'),
                    ForceDeleteBulkAction::make()
                        ->label('Eliminar seleccionados definitivamente')
                        ->fetchSelectedRecords()
                        ->action(function (ForceDeleteBulkAction $action, EloquentCollection | Collection | LazyCollection $records): void {
                            $isFirstException = true;

                            $records->each(static function (Document $record) use ($action, &$isFirstException): void {
                                try {
                                    static::deleteFromGoogleDrive($record);
                                    $record->forceDelete() || $action->reportBulkProcessingFailure();
                                } catch (Throwable $exception) {
                                    $action->reportBulkProcessingFailure();

                                    if ($isFirstException) {
                                        report($exception);
                                        $isFirstException = false;
                                    }
                                }
                            });
                        }),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }

    public static function deleteFromGoogleDrive(Document $record): void
    {
        if (blank($record->gdrive_id)) {
            return;
        }

        GoogleDriveHelper::deleteOrTrashFile($record->gdrive_id);
    }

    protected static function resolveDocumentTypeIcon(?string $fileName): string
    {
        return match (static::resolveDocumentType($fileName)) {
            'pdf' => 'heroicon-o-document',
            'spreadsheet' => 'heroicon-o-table-cells',
            'presentation' => 'heroicon-o-presentation-chart-bar',
            'text' => 'heroicon-o-document-text',
            default => 'heroicon-o-document',
        };
    }

    protected static function resolveDocumentTypeIconColor(?string $fileName): string
    {
        return match (static::resolveDocumentType($fileName)) {
            'pdf' => 'danger',
            'spreadsheet' => 'success',
            'presentation' => 'warning',
            'text' => 'gray',
            default => 'gray',
        };
    }

    protected static function resolveDocumentTypeLabel(?string $fileName): string
    {
        return match (static::resolveDocumentType($fileName)) {
            'pdf' => 'PDF',
            'spreadsheet' => 'Hoja de cálculo',
            'presentation' => 'Presentación',
            'text' => 'Texto',
            default => 'Documento',
        };
    }

    protected static function resolveDocumentType(?string $fileName): string
    {
        $extension = Str::of((string) pathinfo((string) $fileName, PATHINFO_EXTENSION))
            ->lower()
            ->toString();

        return match ($extension) {
            'pdf' => 'pdf',
            'xls', 'xlsx', 'csv', 'tsv', 'ods', 'gsheet' => 'spreadsheet',
            'ppt', 'pptx', 'pps', 'ppsx', 'odp', 'key', 'gslides' => 'presentation',
            'doc', 'docx', 'odt', 'txt', 'rtf', 'md', 'gdoc' => 'text',
            default => 'other',
        };
    }
}
