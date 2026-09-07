<?php

namespace App\Filament\Resources\SupportChatResource\Widgets;

use App\Models\SupportChat;
use App\Models\User;
use Filament\Widgets\Widget;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class SupportChatHistory extends Widget
{
    protected string $view = 'filament.resources.support-chat-resource.widgets.support-chat-history';

    protected int | string | array $columnSpan = 'full';

    public ?SupportChat $record = null;

    public string $message = '';
    public string $message_type = 'text';
    public string $priority = 'medium';

    public function mount(array $data = []): void
    {
        if (isset($data['record']) && $data['record'] instanceof SupportChat) {
            $this->record = $data['record'];
        }
    }

    public function getConversationMessages()
    {
        if (!$this->record) {
            return collect();
        }

        return SupportChat::where('user_id', $this->record->user_id)
            ->with(['user', 'admin'])
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
            'unread_messages' => $messages->where('is_read', false)->count(),
            'conversation_status' => $this->record->status,
            'priority' => $this->record->priority,
            'assigned_admin' => $this->record->admin?->name ?? 'Unassigned',
            'customer' => $this->record->user->name,
            'last_message_at' => $messages->last()?->created_at,
        ];
    }

    public function sendReply(): void
    {
        if (!$this->record) {
            return;
        }

        if (empty($this->message)) {
            Notification::make()
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
            SupportChat::where('user_id', $this->record->user_id)
                ->update(['status' => 'open']);
        }

        Notification::make()
            ->title('Reply sent successfully')
            ->success()
            ->send();

        $this->message = '';
        $this->message_type = 'text';
        $this->priority = 'medium';

        $this->dispatch('$refresh');
    }

    public function markAsRead(SupportChat $message): void
    {
        $message->markAsRead();

        Notification::make()
            ->title('Message marked as read')
            ->success()
            ->send();
    }

    public function assignToMe(): void
    {
        if (!$this->record) {
            return;
        }

        SupportChat::where('user_id', $this->record->user_id)
            ->update(['admin_id' => auth()->id()]);

        Notification::make()
            ->title('Conversation assigned to you')
            ->success()
            ->send();
    }

    public function closeConversation(): void
    {
        if (!$this->record) {
            return;
        }

        SupportChat::where('user_id', $this->record->user_id)
            ->update(['status' => 'closed']);

        Notification::make()
            ->title('Conversation closed')
            ->success()
            ->send();
    }

    public function reopenConversation(): void
    {
        if (!$this->record) {
            return;
        }

        SupportChat::where('user_id', $this->record->user_id)
            ->update(['status' => 'open']);

        Notification::make()
            ->title('Conversation reopened')
            ->success()
            ->send();
    }
}
