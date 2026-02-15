<?php

namespace App\Filament\Resources\AllowedGoogleAccountResource\Pages;

use App\Filament\Resources\AllowedGoogleAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAllowedGoogleAccounts extends ListRecords
{
    protected static string $resource = AllowedGoogleAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
