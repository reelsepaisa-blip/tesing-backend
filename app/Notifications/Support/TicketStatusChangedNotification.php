<?php

namespace App\Notifications\Support;

use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidNotification;

class TicketStatusChangedNotification extends BaseSupportNotification
{
    protected $oldStatus;
    protected $note;

    public function __construct(SupportTicket $ticket, string $oldStatus, ?string $note = null)
    {
        parent::__construct($ticket);
        $this->oldStatus = $oldStatus;
        $this->note = $note;
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Ticket #{$this->ticket->ticket_number} Status Updated")
            ->greeting("Hello {$notifiable->name},")
            ->line("The status of your support ticket has been updated:")
            ->line("Ticket Number: {$this->ticket->ticket_number}")
            ->line("Subject: {$this->ticket->subject}")
            ->line("Previous Status: {$this->oldStatus}")
            ->line("New Status: {$this->ticket->status}");

        if ($this->note) {
            $mail->line("Note: {$this->note}");
        }

        if ($this->ticket->isResolved()) {
            $mail->line('Please let us know if you need any further assistance.');
        }

        return $mail->action('View Ticket', $this->getTicketUrl());
    }

    public function toDatabase($notifiable): array
    {
        $data = $this->getTicketData();
        $data['status_change'] = [
            'old_status' => $this->oldStatus,
            'new_status' => $this->ticket->status,
            'note' => $this->note,
        ];

        return [
            'title' => 'Ticket Status Updated',
            'message' => "Ticket #{$this->ticket->ticket_number} status changed from {$this->oldStatus} to {$this->ticket->status}",
            'type' => 'ticket_status_changed',
            'data' => $data,
        ];
    }

    public function toFcm($notifiable): FcmMessage
    {
        $data = $this->getTicketData();
        $data['status_change'] = [
            'old_status' => $this->oldStatus,
            'new_status' => $this->ticket->status,
            'note' => $this->note,
        ];

        return FcmMessage::create()
            ->setData($data)
            ->setNotification(\NotificationChannels\Fcm\Resources\Notification::create()
                ->setTitle('Ticket Status Updated')
                ->setBody("Ticket #{$this->ticket->ticket_number} status changed to {$this->ticket->status}")
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








