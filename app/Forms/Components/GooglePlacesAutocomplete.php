<?php

namespace App\Forms\Components;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;

class GooglePlacesAutocomplete extends TextInput
{
    protected string $view = 'forms.components.google-places-autocomplete';

    protected string $zoneField = '';
    protected string $locationField = '';

    public function zoneField(string $field): static
    {
        $this->zoneField = $field;
        return $this;
    }

    public function locationField(string $field): static
    {
        $this->locationField = $field;
        return $this;
    }

    public function getZoneField(): string
    {
        return $this->zoneField;
    }

    public function getLocationField(): string
    {
        return $this->locationField;
    }
}
