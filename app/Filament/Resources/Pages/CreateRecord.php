<?php

namespace App\Filament\Resources\Pages;

use Filament\Schemas\Schema;
use Filament\Resources\Pages\CreateRecord as BaseCreateRecord;

class CreateRecord extends BaseCreateRecord
{
    public function defaultForm(Schema $schema): Schema
    {
        if (! $schema->hasCustomColumns()) {
            $schema->columns(1);
        }

        return $schema
            ->inlineLabel($this->hasInlineLabels())
            ->model($this->getModel())
            ->operation('create')
            ->statePath('data');
    }
}
