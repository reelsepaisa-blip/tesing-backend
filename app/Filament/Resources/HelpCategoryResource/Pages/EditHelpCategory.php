<?php

namespace App\Filament\Resources\HelpCategoryResource\Pages;

use App\Filament\Resources\HelpCategoryResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditHelpCategory extends EditRecord
{
    protected static string $resource = HelpCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}








