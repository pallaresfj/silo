<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\DocumentCategory;
use App\Models\Document;
use App\Models\Entity;
use App\Support\GoogleDriveHelper;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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
                        Select::make('storage_scope')
                            ->label('Ubicación en Drive')
                            ->options([
                                Document::STORAGE_SCOPE_YEARLY => 'Por año',
                                Document::STORAGE_SCOPE_INSTITUTIONAL => 'Institucional (' . GoogleDriveHelper::getInstitutionalFolderName() . ')',
                            ])
                            ->default(Document::STORAGE_SCOPE_YEARLY)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                $year = (int) ($get('year') ?? now()->year);
                                $title = (string) ($get('title') ?? '');
                                $yearPrefix = "{$year} - ";

                                if (trim($title) === '' || preg_match('/^\d{4} - $/', $title) === 1) {
                                    $set('title', $yearPrefix);
                                }
                            })
                            ->helperText(fn (Get $get): string => ($get('storage_scope') ?? Document::STORAGE_SCOPE_YEARLY) === Document::STORAGE_SCOPE_INSTITUTIONAL
                                ? 'Guarda el archivo en la carpeta especial ' . GoogleDriveHelper::getInstitutionalFolderName() . '. El año sigue usándose como metadato y para sugerir el prefijo del título.'
                                : 'Guarda el archivo bajo la estructura anual existente.')
                            ->columnSpanFull(),

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
                            ->default(fn (string $operation): ?string => $operation === 'create' ? now()->year . ' - ' : null)
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('category_id')
                            ->label('Categoría')
                            ->relationship('category', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->authorize(fn (): bool => Gate::allows('create', DocumentCategory::class))
                                ->authorizationNotification()
                                ->authorizationMessage('No tienes permisos para crear categorías.')
                            )
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('slug')
                                    ->label('Slug')
                                    ->maxLength(100)
                                    ->unique(DocumentCategory::class, 'slug')
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
                            ->createOptionAction(fn (Action $action): Action => $action
                                ->authorize(fn (): bool => Gate::allows('create', Entity::class))
                                ->authorizationNotification()
                                ->authorizationMessage('No tienes permisos para crear entidades.')
                            )
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
                            ->label(fn (Get $get): string => ($get('storage_scope') ?? Document::STORAGE_SCOPE_YEARLY) === Document::STORAGE_SCOPE_INSTITUTIONAL
                                ? 'Año (metadato)'
                                : 'Año')
                            ->numeric()
                            ->default(now()->year)
                            ->live()
                            ->helperText(fn (Get $get): ?string => ($get('storage_scope') ?? Document::STORAGE_SCOPE_YEARLY) === Document::STORAGE_SCOPE_INSTITUTIONAL
                                ? 'Se conserva para filtros y reportes, pero no define la carpeta física.'
                                : null)
                            ->afterStateUpdated(function (Get $get, Set $set, mixed $state, mixed $old): void {
                                if (! is_numeric($state)) {
                                    return;
                                }

                                $newPrefix = ((int) $state) . ' - ';
                                $title = (string) ($get('title') ?? '');

                                if (trim($title) === '' || preg_match('/^\d{4} - $/', $title) === 1) {
                                    $set('title', $newPrefix);

                                    return;
                                }

                                if (! is_numeric($old)) {
                                    return;
                                }

                                $oldPrefix = ((int) $old) . ' - ';

                                if (Str::startsWith($title, $oldPrefix)) {
                                    $set('title', $newPrefix . Str::after($title, $oldPrefix));
                                }
                            })
                            ->required()
                            ->minValue(1900)
                            ->maxValue(2099),

                        Select::make('status')
                            ->label('Estado')
                            ->options(static::getDocumentStatusOptions())
                            ->default('Borrador')
                            ->required(),

                        TagsInput::make('metadata.tags')
                            ->label('Etiquetas')
                            ->placeholder('Agregar etiqueta...')
                            ->splitKeys([',', 'Tab'])
                            ->helperText('Escribe una etiqueta y presiona Enter, coma o Tab para agregar otra.')
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
                    ->badge()
                    ->color('gray'),

                TextColumn::make('storage_scope')
                    ->label('Ubicación')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === Document::STORAGE_SCOPE_INSTITUTIONAL
                        ? 'Institucional'
                        : 'Por año')
                    ->color(fn (?string $state): string => $state === Document::STORAGE_SCOPE_INSTITUTIONAL ? 'info' : 'gray')
                    ->toggleable(),

                TextColumn::make('title')
                    ->label('Título')
                    ->weight(FontWeight::Bold)
                    ->icon(fn (Document $record): string => static::resolveDocumentTypeIcon($record->file_name))
                    ->iconColor(fn (Document $record): string => static::resolveDocumentTypeIconColor($record->file_name))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = Str::of($search)->lower()->trim()->toString();

                        if ($term === '') {
                            return $query;
                        }

                        $likeTerm = "%{$term}%";

                        return $query->where(function (Builder $subQuery) use ($likeTerm): void {
                            $subQuery
                                ->whereRaw('LOWER(title) LIKE ?', [$likeTerm])
                                ->orWhereRaw("LOWER(COALESCE(JSON_EXTRACT(metadata, '$.tags'), '')) LIKE ?", [$likeTerm]);
                        });
                    })
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

                TextColumn::make('metadata.tags')
                    ->label('Etiquetas')
                    ->lineClamp(2)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                fn (Document $record): ?string => $record->resolveOpenUrlForCurrentUser(),
                shouldOpenInNewTab: true
            )
            ->groups([
                Group::make('year')
                    ->label('Año')
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderBy('year', 'desc')),
            ])
            ->defaultGroup('year')
            ->groupingSettingsHidden()
            ->groupingDirectionSettingHidden()
            ->defaultSort('updated_at', 'desc')
            ->paginationPageOptions([10, 20, 50, 100, 200])
            ->defaultPaginationPageOption(10)
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

                SelectFilter::make('storage_scope')
                    ->label('Ubicación')
                    ->options([
                        Document::STORAGE_SCOPE_YEARLY => 'Por año',
                        Document::STORAGE_SCOPE_INSTITUTIONAL => 'Institucional',
                    ]),

                SelectFilter::make('category')
                    ->label('Categoría')
                    ->relationship('category', 'name'),

                SelectFilter::make('dashboard_bucket')
                    ->label('Condición dashboard')
                    ->options([
                        'pending' => 'Pendientes',
                        'approved' => 'Aprobados',
                        'archived' => 'Archivados',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'pending' => $query->whereIn('status', [
                                'Borrador',
                                'Pendiente_OCR',
                                'Importado_Sin_Clasificar',
                            ]),
                            'approved' => $query->where('status', 'Publicado'),
                            'archived' => $query
                                ->withTrashed()
                                ->where(function (Builder $subQuery): void {
                                    $subQuery
                                        ->where('status', 'Archivado')
                                        ->orWhereNotNull('deleted_at');
                                }),
                            default => $query,
                        };
                    }),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(static::getDocumentStatusOptions()),

                TrashedFilter::make(),
            ])
            ->actions([
                Action::make('openDrive')
                    ->label('Abrir en Drive')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Abrir en Drive')
                    ->url(
                        fn (Document $record): ?string => $record->resolveOpenUrlForCurrentUser(),
                        shouldOpenInNewTab: true
                    )
                    ->visible(fn (Document $record): bool => filled($record->resolveOpenUrlForCurrentUser())),

                Action::make('preview')
                    ->label('Vista Previa')
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Vista previa')
                    ->modalHeading(fn($record): string => $record->title)
                    ->modalContent(fn($record) => view('filament.resources.document-resource.preview', [
                        'url' => $record->resolveOpenUrlForCurrentUser(),
                    ]))
                    ->modalWidth('7xl')
                    ->visible(fn($record): bool => filled($record->resolveOpenUrlForCurrentUser())),

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
                    BulkAction::make('bulkUpdateAttributes')
                        ->label('Actualizar seleccionados')
                        ->icon('heroicon-o-pencil-square')
                        ->modalHeading('Actualizar documentos seleccionados')
                        ->modalDescription('Solo se modificarán los campos que diligencies. Si dejas un campo vacío, se conservará su valor actual. Los documentos en papelera no se actualizarán.')
                        ->modalSubmitActionLabel('Aplicar cambios')
                        ->fetchSelectedRecords()
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => auth()->user()?->hasPermission('documents.update') ?? false)
                        ->authorizeIndividualRecords('update')
                        ->form([
                            Select::make('status')
                                ->label('Estado')
                                ->options(static::getDocumentStatusOptions()),
                            Select::make('category_id')
                                ->label('Categoría')
                                ->relationship('category', 'name')
                                ->searchable()
                                ->preload(),
                            Select::make('entity_mode')
                                ->label('Cambio de entidad')
                                ->options([
                                    'keep' => 'No cambiar',
                                    'set' => 'Asignar entidad',
                                    'clear' => 'Quitar entidad',
                                ])
                                ->default('keep')
                                ->live(),
                            Select::make('entity_id')
                                ->label('Entidad')
                                ->relationship('entity', 'name')
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => ($get('entity_mode') ?? 'keep') === 'set')
                                ->required(fn (Get $get): bool => ($get('entity_mode') ?? 'keep') === 'set'),
                        ])
                        ->action(function (EloquentCollection | Collection | LazyCollection $records, array $data): void {
                            $selectedRecords = $records instanceof EloquentCollection
                                ? $records
                                : new EloquentCollection($records->all());

                            $payload = static::buildBulkUpdatePayload($data);

                            if ($payload === []) {
                                Notification::make()
                                    ->warning()
                                    ->title('No se aplicaron cambios')
                                    ->body('Debes seleccionar al menos un estado, una categoría o un cambio de entidad.')
                                    ->send();

                                return;
                            }

                            /** @var EloquentCollection<int, Document> $eligibleRecords */
                            $eligibleRecords = $selectedRecords->filter(
                                fn (Document $record): bool => $record->deleted_at === null
                            );
                            $skippedCount = $selectedRecords->count() - $eligibleRecords->count();

                            if ($eligibleRecords->isEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('No se actualizaron documentos')
                                    ->body('Los documentos seleccionados están en papelera. Debes restaurarlos antes de editarlos en bloque.')
                                    ->send();

                                return;
                            }

                            Document::query()
                                ->whereKey($eligibleRecords->modelKeys())
                                ->update($payload);

                            $updatedCount = $eligibleRecords->count();
                            $updatedLabel = Str::plural('documento', $updatedCount);
                            $body = "{$updatedCount} {$updatedLabel} actualizados.";

                            if ($skippedCount > 0) {
                                $skippedLabel = Str::plural('documento', $skippedCount);
                                $body .= " {$skippedCount} {$skippedLabel} omitidos por estar en papelera.";
                            }

                            Notification::make()
                                ->success()
                                ->title('Documentos actualizados')
                                ->body($body)
                                ->send();
                        }),
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

    /**
     * @return array<string, string>
     */
    protected static function getDocumentStatusOptions(): array
    {
        return [
            'Borrador' => 'Borrador',
            'Publicado' => 'Publicado',
            'Archivado' => 'Archivado',
            'Pendiente_OCR' => 'Pendiente OCR',
            'Importado_Sin_Clasificar' => 'Importado Sin Clasificar',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function buildBulkUpdatePayload(array $data): array
    {
        $payload = [];

        if (filled($data['status'] ?? null)) {
            $payload['status'] = $data['status'];
        }

        if (filled($data['category_id'] ?? null)) {
            $payload['category_id'] = $data['category_id'];
        }

        $entityMode = $data['entity_mode'] ?? 'keep';

        if ($entityMode === 'set' && filled($data['entity_id'] ?? null)) {
            $payload['entity_id'] = $data['entity_id'];
        }

        if ($entityMode === 'clear') {
            $payload['entity_id'] = null;
        }

        return $payload;
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
