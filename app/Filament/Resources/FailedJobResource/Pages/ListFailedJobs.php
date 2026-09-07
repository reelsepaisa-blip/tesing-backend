<?php

namespace App\Filament\Resources\FailedJobResource\Pages;

use Filament\Actions\Action;
use App\Filament\Resources\FailedJobResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListFailedJobs extends ListRecords
{
    protected static string $resource = FailedJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_queue_status')
                ->label('Queue Status')
                ->icon('heroicon-o-queue-list')
                ->color('info')
                ->action(function () {

                    return redirect()->to('/horizon');
                })
                ->visible(fn() => config('queue.default') === 'redis'),
        ];
    }
}
