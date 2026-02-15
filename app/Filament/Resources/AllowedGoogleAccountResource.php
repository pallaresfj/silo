<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AllowedGoogleAccountResource\Pages;
use App\Models\AllowedGoogleAccount;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class AllowedGoogleAccountResource extends Resource
{
    protected static ?string $model = AllowedGoogleAccount::class;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-user-plus';
    }

    public static function getNavigationLabel(): string
    {
        return 'Lista Blanca Google';
    }

    public static function getModelLabel(): string
    {
        return 'Cuenta autorizada';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Cuentas autorizadas';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Seguridad';
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cuenta autorizada')
                    ->columns(2)
                    ->schema([
                        TextInput::make('email')
                            ->label('Correo Google')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        Select::make('default_role_slug')
                            ->label('Rol por defecto')
                            ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'slug')->all())
                            ->searchable()
                            ->preload(),

                        Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('default_role_slug')
                    ->label('Rol por defecto')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::title(str_replace('_', ' ', (string) $state)) : 'Sin rol'),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('email')
            ->actions([
                EditAction::make()
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Editar'),

                DeleteAction::make()
                    ->iconButton()
                    ->hiddenLabel()
                    ->tooltip('Borrar'),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAllowedGoogleAccounts::route('/'),
            'create' => Pages\CreateAllowedGoogleAccount::route('/create'),
            'edit' => Pages\EditAllowedGoogleAccount::route('/{record}/edit'),
        ];
    }
}
