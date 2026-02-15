<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Mi Perfil';

    protected static ?int $navigationSort = 100;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function isSimple(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Imagen de Perfil')
                    ->description('Personaliza tu avatar para identificarte en el sistema.')
                    ->schema([
                        FileUpload::make('avatar_url')
                            ->label('Foto de Perfil')
                            ->avatar()
                            ->disk('public')
                            ->directory('avatars')
                            ->image()
                            ->imageEditor()
                            ->maxSize(2048)
                            ->circleCropper()
                            ->columnSpanFull(),
                    ]),

                $this->getNameFormComponent()
                    ->label('Nombre Completo'),
                $this->getEmailFormComponent()
                    ->label('Correo Electrónico')
                    ->disabled(),
            ]);
    }

    protected function getRedirectUrl(): ?string
    {
        return Filament::getCurrentOrDefaultPanel()->getUrl();
    }
}
