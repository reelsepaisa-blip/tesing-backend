@component('mail::message')
# New Support Ticket Created

Dear {{ $notifiable->name }},

A new support ticket has been created with the following details:

**Ticket Number:** {{ $ticket->ticket_number }}  
**Subject:** {{ $ticket->subject }}  
**Category:** {{ ucfirst($ticket->category) }}  
**Priority:** {{ ucfirst($ticket->priority) }}

@if($ticket->booking_id)
**Related Booking:** {{ $ticket->booking->booking_code }}
@endif

Our support team will review your ticket and respond as soon as possible.

@component('mail::button', ['url' => $url])
View Ticket
@endcomponent

Thank you for your patience.

Best regards,  
{{ config('app.name') }} Support Team

@component('mail::subcopy')
If you're having trouble clicking the "View Ticket" button, copy and paste this URL into your web browser: {{ $url }}
@endcomponent
@endcomponent








