<?php

namespace App\Filament\Resources\SystemHealthResource\Pages;

use App\Filament\Resources\SystemHealthResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListSystemHealth extends ListRecords
{
    protected static string $resource = SystemHealthResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view_dashboard')
                ->label('View Dashboard')
                ->icon('heroicon-o-chart-bar')
                ->color('info')
                ->url(fn() => static::getResource()::getUrl('dashboard')),
        ];
    }
}
