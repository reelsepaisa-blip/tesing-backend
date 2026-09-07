<?php

namespace App\Forms\Components;

use Filament\Forms\Components\TextInput;

class CityAutocomplete extends TextInput
{
    protected string $view = 'forms.components.city-autocomplete';

    protected string $stateField = 'state';
    protected string $countryField = 'country';
    protected string $latitudeField = 'latitude';
    protected string $longitudeField = 'longitude';

    public function stateField(string $field): static
    {
        $this->stateField = $field;
        return $this;
    }

    public function countryField(string $field): static
    {
        $this->countryField = $field;
        return $this;
    }

    public function latitudeField(string $field): static
    {
        $this->latitudeField = $field;
        return $this;
    }

    public function longitudeField(string $field): static
    {
        $this->longitudeField = $field;
        return $this;
    }

    public function getStateField(): string
    {
        return $this->stateField;
    }

    public function getCountryField(): string
    {
        return $this->countryField;
    }

    public function getLatitudeField(): string
    {
        return $this->latitudeField;
    }

    public function getLongitudeField(): string
    {
        return $this->longitudeField;
    }
}
