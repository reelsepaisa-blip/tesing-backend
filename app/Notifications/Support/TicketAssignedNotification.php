<?php

namespace App\Notifications\Support;

use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidNotification;

class TicketAssignedNotification extends BaseSupportNotification
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Support Ticket #{$this->ticket->ticket_number} Assigned")
            ->greeting("Hello {$notifiable->name},")
            ->line("A support ticket has been assigned to you:")
            ->line("Ticket Number: {$this->ticket->ticket_number}")
            ->line("Subject: {$this->ticket->subject}")
            ->line("Category: {$this->ticket->category}")
            ->line("Priority: {$this->ticket->priority}")
            ->line("User: {$this->ticket->user->name}")
            ->action('View Ticket', $this->getAdminTicketUrl())
            ->line('Please review the ticket and take appropriate action.');
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Ticket Assigned',
            'message' => "Ticket #{$this->ticket->ticket_number} has been assigned to you.",
            'type' => 'ticket_assigned',
            'data' => $this->getAdminTicketData(),
        ];
    }

    public function toFcm($notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->setData($this->getAdminTicketData())
            ->setNotification(\NotificationChannels\Fcm\Resources\Notification::create()
                ->setTitle('Ticket Assigned')
                ->setBody("Ticket #{$this->ticket->ticket_number} has been assigned to you.")
            )
            ->setAndroid(
                AndroidConfig::create()
                    ->setNotification(AndroidNotification::create()
                        ->setIcon('ic_notification')
                        ->setColor('#FF0000')
                        ->setClickAction('OPEN_ADMIN_TICKET')
                    )
            );
    }
}








