<?php

namespace App\Filament\Resources\DriverResource\Widgets;

use Filament\Widgets\Widget;

class DriverNoteWidget extends Widget
{
    protected string $view = 'filament.resources.city-resource.pages.note';

    protected int | string | array $columnSpan = 'full';

    public string $message = 'When a city is created in the demo panel, the corresponding zone, drivers, and driver approvals will be added automatically.';
}
