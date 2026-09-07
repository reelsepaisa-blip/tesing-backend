<?php

namespace App\Notifications\Support;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidNotification;

class TicketRepliedNotification extends BaseSupportNotification
{
    protected $message;

    public function __construct(SupportTicket $ticket, SupportMessage $message)
    {
        parent::__construct($ticket);
        $this->message = $message;
    }

    public function toMail($notifiable): MailMessage
    {
        $isAdmin = $notifiable->id === $this->ticket->assigned_to;
        $from = $this->message->user->name;
        $preview = str()->limit(strip_tags($this->message->message), 100);

        return (new MailMessage)
            ->subject("New Reply on Ticket #{$this->ticket->ticket_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$from} has replied to the ticket:")
            ->line("Ticket Number: {$this->ticket->ticket_number}")
            ->line("Subject: {$this->ticket->subject}")
            ->line("Reply: {$preview}")
            ->action('View Reply', $isAdmin ? $this->getAdminTicketUrl() : $this->getTicketUrl())
            ->line('Click the button above to view the full message and respond.');
    }

    public function toDatabase($notifiable): array
    {
        $isAdmin = $notifiable->id === $this->ticket->assigned_to;
        $data = $isAdmin ? $this->getAdminTicketData() : $this->getTicketData();
        
        $data['message'] = [
            'id' => $this->message->id,
            'user_id' => $this->message->user_id,
            'user_name' => $this->message->user->name,
            'preview' => str()->limit(strip_tags($this->message->message), 100),
            'has_attachments' => $this->message->attachments()->exists(),
        ];

        return [
            'title' => 'New Ticket Reply',
            'message' => "New reply from {$this->message->user->name} on ticket #{$this->ticket->ticket_number}",
            'type' => 'ticket_replied',
            'data' => $data,
        ];
    }

    public function toFcm($notifiable): FcmMessage
    {
        $isAdmin = $notifiable->id === $this->ticket->assigned_to;
        $data = $isAdmin ? $this->getAdminTicketData() : $this->getTicketData();
        
        return FcmMessage::create()
            ->setData($data)
            ->setNotification(\NotificationChannels\Fcm\Resources\Notification::create()
                ->setTitle('New Ticket Reply')
                ->setBody("New reply from {$this->message->user->name} on ticket #{$this->ticket->ticket_number}")
            )
            ->setAndroid(
                AndroidConfig::create()
                    ->setNotification(AndroidNotification::create()
                        ->setIcon('ic_notification')
                        ->setColor('#FF0000')
                        ->setClickAction($isAdmin ? 'OPEN_ADMIN_TICKET' : 'OPEN_TICKET')
                    )
            );
    }
}








