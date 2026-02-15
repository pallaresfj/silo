<?php

namespace App\Filament\Pages;

use App\Support\Dashboard\HomeDashboardDataBuilder;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Widgets\Widget;

class HomeDashboard extends Dashboard
{
    protected static ?string $title = 'Inicio';

    protected static ?string $navigationLabel = 'Inicio';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected string $view = 'filament.pages.home-dashboard';

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(HomeDashboardDataBuilder::class)->build();
    }
}
