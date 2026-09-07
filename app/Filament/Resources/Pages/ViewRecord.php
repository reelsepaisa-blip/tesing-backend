<?php

namespace App\Filament\Resources\Pages;

use Filament\Schemas\Schema;
use Filament\Resources\Pages\ViewRecord as BaseViewRecord;

class ViewRecord extends BaseViewRecord
{
    public function defaultForm(Schema $schema): Schema
    {
        if (! $schema->hasCustomColumns()) {
            $schema->columns(1);
        }

        return $schema
            ->disabled()
            ->inlineLabel($this->hasInlineLabels())
            ->model($this->getRecord())
            ->operation('view')
            ->statePath('data');
    }

    public function defaultInfolist(Schema $schema): Schema
    {
        if (! $schema->hasCustomColumns()) {
            $schema->columns(1);
        }

        return $schema
            ->inlineLabel($this->hasInlineLabels())
            ->record($this->getRecord());
    }
}
