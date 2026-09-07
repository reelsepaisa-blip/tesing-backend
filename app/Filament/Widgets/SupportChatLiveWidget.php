<?php

namespace App\Filament\Widgets;

use App\Models\SupportChat;
use App\Models\User;
use Filament\Widgets\Widget;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class SupportChatLiveWidget extends Widget
{
    protected string $view = 'filament.widgets.support-chat-live';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return false; // Disable the widget
    }

    public $selectedUser = null;
    public $message = '';
    public $messageType = 'text';
    public $priority = 'medium';
    public $attachment = null;

    public function mount(): void
    {
    }

    public function sendMessage(): void
    {
        if (!$this->selectedUser) {
            Notification::make()
                ->title('Please select a customer')
                ->warning()
                ->send();
            return;
        }

        if (empty($this->message)) {
            Notification::make()
                ->title('Please enter a message')
                ->warning()
                ->send();
            return;
        }

        $supportChat = SupportChat::create([
            'user_id' => $this->selectedUser,
            'admin_id' => Auth::id(),
            'sender_type' => 'admin',
            'message' => $this->message,
            'message_type' => $this->messageType,
            'priority' => $this->priority,
            'metadata' => $this->attachment ? [
                'attachment_path' => $this->attachment,
                'attachment_url' => asset('storage/' . $this->attachment),
            ] : null,
        ]);

        Notification::make()
            ->title('Message sent successfully')
            ->success()
            ->send();

        $this->message = '';
        $this->attachment = null;
    }

    public function getRecentMessages()
    {
        return SupportChat::with(['user', 'admin'])
            ->latest()
            ->limit(10)
            ->get();
    }

    public function getUnassignedConversations()
    {
        return SupportChat::whereNull('admin_id')
            ->with(['user'])
            ->distinct('user_id')
            ->latest()
            ->get();
    }

    public function getMyAssignedConversations()
    {
        return SupportChat::where('admin_id', Auth::id())
            ->with(['user'])
            ->distinct('user_id')
            ->latest()
            ->get();
    }

    public function assignToMe($userId): void
    {
        SupportChat::where('user_id', $userId)
            ->update(['admin_id' => Auth::id()]);

        Notification::make()
            ->title('Conversation assigned to you')
            ->success()
            ->send();
    }

    public function getConversationMessages($userId)
    {
        return SupportChat::where('user_id', $userId)
            ->with(['user', 'admin'])
            ->orderBy('created_at', 'asc')
            ->get();
    }
}
