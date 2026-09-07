<?php

namespace App\Filament\Resources\SupportChatResource\Pages;

use App\Filament\Resources\SupportChatResource;
use App\Models\SupportChat;
use Filament\Actions;
use App\Filament\Resources\Pages\ViewRecord;

class ChatSupportChat extends ViewRecord
{
    protected static string $resource = SupportChatResource::class;

    protected string $view = 'filament.resources.support-chat-resource.pages.chat-support-chat';

    protected static ?string $title = 'Chat';

    protected static ?string $navigationLabel = 'Chat';

    public string $message = '';
    public string $message_type = 'text';
    public string $priority = 'medium';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('close_conversation')
                ->label('Close Conversation')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->action(function (): void {
                    if ($this->record->booking_id) {
                        SupportChat::where('booking_id', $this->record->booking_id)
                            ->update(['status' => 'closed']);
                    } else {
                        SupportChat::where('user_id', $this->record->user_id)
                            ->update(['status' => 'closed']);
                    }

                    \Filament\Notifications\Notification::make()
                        ->title('Conversation closed')
                        ->success()
                        ->send();
                })
                ->visible(fn(): bool => $this->record->status !== 'closed'),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [

        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 0; // This prevents automatic footer widget rendering
    }

    public function getConversationMessages()
    {
        if (!$this->record) {
            return collect();
        }

        if ($this->record->booking_id) {
            return SupportChat::where('booking_id', $this->record->booking_id)
                ->with(['user', 'admin', 'booking'])
                ->orderBy('created_at', 'asc')
                ->get();
        }

        return SupportChat::where('user_id', $this->record->user_id)
            ->with(['user', 'admin', 'booking'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getConversationStats()
    {
        if (!$this->record) {
            return [];
        }

        $messages = $this->getConversationMessages();

        return [
            'total_messages' => $messages->count(),
            'user_messages' => $messages->where('sender_type', 'user')->count(),
            'admin_messages' => $messages->where('sender_type', 'admin')->count(),
            'unread_messages' => $messages->where('is_read', false)->where('sender_type', 'user')->count(),
        ];
    }

    public function sendReply(): void
    {
        if (!$this->record) {
            return;
        }

        if (empty($this->message)) {
            \Filament\Notifications\Notification::make()
                ->title('Message is required')
                ->danger()
                ->send();
            return;
        }

        $reply = SupportChat::create([
            'user_id' => $this->record->user_id,
            'booking_id' => $this->record->booking_id,
            'admin_id' => auth()->id(),
            'sender_type' => 'admin',
            'message' => $this->message,
            'message_type' => $this->message_type,
            'priority' => $this->priority,
            'is_read' => false,
            'status' => 'open',
        ]);

        $reply->load(['user', 'admin']);

        event(new \App\Events\SupportChatMessage($reply));

        if ($this->record->status === 'closed') {
            if ($this->record->booking_id) {
                SupportChat::where('booking_id', $this->record->booking_id)
                    ->update(['status' => 'open']);
            } else {
                SupportChat::where('user_id', $this->record->user_id)
                    ->update(['status' => 'open']);
            }
        }

        \Filament\Notifications\Notification::make()
            ->title('Reply sent successfully')
            ->success()
            ->send();

        $this->message = '';
        $this->message_type = 'text';
        $this->priority = 'medium';

        $this->dispatch('$refresh');
    }


    public function refreshMessages(): void
    {


    }
}
