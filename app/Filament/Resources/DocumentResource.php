<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
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
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

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
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(fn($record): string => $record->title),

                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->badge()
                    ->color('primary'),

                TextColumn::make('entity.name')
                    ->label('Entidad')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('year')
                    ->label('Año')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

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

                IconColumn::make('gdrive_url')
                    ->label('Drive')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn($record): ?string => $record->gdrive_url, shouldOpenInNewTab: true)
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-top-right-on-square')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
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
                    ->modalHeading(fn($record): string => $record->title)
                    ->modalContent(fn($record) => view('filament.resources.document-resource.preview', [
                        'url' => $record->gdrive_url,
                    ]))
                    ->modalWidth('7xl')
                    ->visible(fn($record): bool => !empty($record->gdrive_url)),

                Action::make('open_drive')
                    ->label('Abrir en Drive')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn($record): ?string => $record->gdrive_url, shouldOpenInNewTab: true)
                    ->visible(fn($record): bool => !empty($record->gdrive_url))
                    ->color('info'),

                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
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
}
