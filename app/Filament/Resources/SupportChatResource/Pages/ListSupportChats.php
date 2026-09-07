<?php

namespace App\Filament\Resources\SupportChatResource\Pages;

use App\Filament\Resources\SupportChatResource;
use Filament\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListSupportChats extends ListRecords
{
    protected static string $resource = SupportChatResource::class;

    protected string $view = 'filament.resources.support-chat-resource.pages.list-support-chats';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
