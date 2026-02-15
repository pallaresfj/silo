<?php

namespace App\Filament\Auth;

use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('google')
                ->label('Entrar con Google')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->url(route('auth.google.redirect')),
        ];
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Acceso institucional';
    }

    public function getSubheading(): string | Htmlable | null
    {
        return 'Ingresa con tu cuenta de Google institucional para continuar.';
    }
}
