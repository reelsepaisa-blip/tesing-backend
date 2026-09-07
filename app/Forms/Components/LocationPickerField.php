<?php

namespace App\Forms\Components;

use Filament\Forms\Components\Field;

class LocationPickerField extends Field
{
    protected string $view = 'forms.components.location-picker-field';

    protected string $latitudeField = 'latitude';

    protected string $longitudeField = 'longitude';

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

    public function getLatitudeField(): string
    {
        return $this->latitudeField;
    }

    public function getLongitudeField(): string
    {
        return $this->longitudeField;
    }

    public function getMapApiKey(): ?string
    {
        if (!config('services.google_maps.enabled')) {
            return null;
        }
        return config('services.google_maps.api_key');
    }
}
