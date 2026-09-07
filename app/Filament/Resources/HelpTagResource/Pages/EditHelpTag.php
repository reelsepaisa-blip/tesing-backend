<?php

namespace App\Filament\Resources\HelpTagResource\Pages;

use App\Filament\Resources\HelpTagResource;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

class EditHelpTag extends EditRecord
{
    protected static string $resource = HelpTagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}


