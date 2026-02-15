<?php

namespace App\Filament\Resources\AllowedGoogleAccountResource\Pages;

use App\Filament\Resources\AllowedGoogleAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAllowedGoogleAccount extends EditRecord
{
    protected static string $resource = AllowedGoogleAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
