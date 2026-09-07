<?php

namespace App\Filament\Resources\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Resources\Pages\EditRecord as BaseEditRecord;

class EditRecord extends BaseEditRecord
{
    public function defaultForm(Schema $schema): Schema
    {
        if (! $schema->hasCustomColumns()) {
            $schema->columns(1);
        }

        return $schema
            ->inlineLabel($this->hasInlineLabels())
            ->model($this->getRecord())
            ->operation('edit')
            ->statePath('data');
    }

    public function getDefaultActionSchemaResolver(Action $action): ?Closure
    {
        return match (true) {
            $action instanceof CreateAction => fn (Schema $schema): Schema => static::getResource()::form($schema->hasCustomColumns() ? $schema : $schema->columns(1)),
            $action instanceof EditAction => fn (Schema $schema): Schema => $schema->components([EmbeddedSchema::make('form')]),
            $action instanceof ViewAction => fn (Schema $schema): Schema => static::getResource()::infolist(static::getResource()::form($schema->hasCustomColumns() ? $schema : $schema->columns(1))),
            default => null,
        };
    }
}
