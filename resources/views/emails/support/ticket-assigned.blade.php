@component('mail::message')
# Support Ticket Assigned

Dear {{ $notifiable->name }},

A support ticket has been assigned to you:

**Ticket Number:** {{ $ticket->ticket_number }}  
**Subject:** {{ $ticket->subject }}  
**Category:** {{ ucfirst($ticket->category) }}  
**Priority:** {{ ucfirst($ticket->priority) }}

### User Details:
**Name:** {{ $ticket->user->name }}  
**Email:** {{ $ticket->user->email }}  
**Phone:** {{ $ticket->user->phone }}

@if($ticket->booking_id)
### Related Booking:
**Booking Code:** {{ $ticket->booking->booking_code }}  
**Status:** {{ ucfirst($ticket->booking->status) }}
@endif

@component('mail::button', ['url' => $url])
View Ticket
@endcomponent

Please review the ticket and take appropriate action based on its priority.

Best regards,  
{{ config('app.name') }} Support Team

@component('mail::subcopy')
If you're having trouble clicking the "View Ticket" button, copy and paste this URL into your web browser: {{ $url }}
@endcomponent
@endcomponent








