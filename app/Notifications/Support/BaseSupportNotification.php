<?php

namespace App\Notifications\Support;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

abstract class BaseSupportNotification extends Notification
{
    use Queueable;

    protected $ticket;

    public function __construct(SupportTicket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function via($notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->email) {
            $channels[] = 'mail';
        }

        if ($notifiable->device_token) {
            $channels[] = 'fcm';
        }

        return $channels;
    }

    protected function getTicketUrl(): string
    {
        return config('app.url') . '/support/tickets/' . $this->ticket->id;
    }

    protected function getAdminTicketUrl(): string
    {
        return config('app.url') . '/admin/support/tickets/' . $this->ticket->id;
    }

    protected function getTicketData(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'subject' => $this->ticket->subject,
            'category' => $this->ticket->category,
            'priority' => $this->ticket->priority,
            'status' => $this->ticket->status,
            'url' => $this->getTicketUrl(),
        ];
    }

    protected function getAdminTicketData(): array
    {
        return array_merge($this->getTicketData(), [
            'user' => [
                'id' => $this->ticket->user->id,
                'name' => $this->ticket->user->name,
                'email' => $this->ticket->user->email,
                'phone' => $this->ticket->user->phone,
            ],
            'url' => $this->getAdminTicketUrl(),
        ]);
    }
}








