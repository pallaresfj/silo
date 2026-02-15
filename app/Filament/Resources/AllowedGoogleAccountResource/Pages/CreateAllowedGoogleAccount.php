<?php

namespace App\Filament\Resources\AllowedGoogleAccountResource\Pages;

use App\Filament\Resources\AllowedGoogleAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAllowedGoogleAccount extends CreateRecord
{
    protected static string $resource = AllowedGoogleAccountResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
