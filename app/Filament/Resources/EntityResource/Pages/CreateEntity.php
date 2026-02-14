<?php

namespace App\Filament\Resources\EntityResource\Pages;

use App\Filament\Resources\EntityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEntity extends CreateRecord
{
    protected static string $resource = EntityResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}

