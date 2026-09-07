<?php

namespace App\Notifications\Support;

use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidNotification;

class TicketCreatedNotification extends BaseSupportNotification
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New Support Ticket #{$this->ticket->ticket_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new support ticket has been created with the following details:")
            ->line("Ticket Number: {$this->ticket->ticket_number}")
            ->line("Subject: {$this->ticket->subject}")
            ->line("Category: {$this->ticket->category}")
            ->line("Priority: {$this->ticket->priority}")
            ->action('View Ticket', $this->getTicketUrl())
            ->line('Our support team will review your ticket and respond as soon as possible.')
            ->line('Thank you for your patience.');
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Support Ticket Created',
            'message' => "Your ticket #{$this->ticket->ticket_number} has been created successfully.",
            'type' => 'ticket_created',
            'data' => $this->getTicketData(),
        ];
    }

    public function toFcm($notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->setData($this->getTicketData())
            ->setNotification(\NotificationChannels\Fcm\Resources\Notification::create()
                ->setTitle('Support Ticket Created')
                ->setBody("Your ticket #{$this->ticket->ticket_number} has been created successfully.")
            )
            ->setAndroid(
                AndroidConfig::create()
                    ->setNotification(AndroidNotification::create()
                        ->setIcon('ic_notification')
                        ->setColor('#FF0000')
                        ->setClickAction('OPEN_TICKET')
                    )
            );
    }
}








