<?php

namespace App\Forms\Components;

use Filament\Forms\Components\Field;

class LocationPicker extends Field
{
    protected string $view = 'forms.components.location-picker';

    public function getMapApiKey(): string
    {
        return 'AIzaSyDTSKbF3tjgvdx4oCPcJ7Fc-PhwCugkZm4';
    }
}























