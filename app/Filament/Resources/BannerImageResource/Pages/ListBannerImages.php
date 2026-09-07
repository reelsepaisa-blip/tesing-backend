<?php

namespace App\Filament\Resources\BannerImageResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\BannerImageResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListBannerImages extends ListRecords
{
    protected static string $resource = BannerImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
