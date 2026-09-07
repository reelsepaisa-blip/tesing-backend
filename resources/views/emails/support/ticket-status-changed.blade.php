@component('mail::message')
# Support Ticket Status Updated

Dear {{ $notifiable->name }},

The status of your support ticket has been updated:

**Ticket Number:** {{ $ticket->ticket_number }}  
**Subject:** {{ $ticket->subject }}  
**Previous Status:** {{ ucfirst($oldStatus) }}  
**New Status:** {{ ucfirst($ticket->status) }}

@if($note)
### Note:
{{ $note }}
@endif

@component('mail::button', ['url' => $url])
View Ticket
@endcomponent

@if($ticket->isResolved())
If you're satisfied with the resolution, no further action is needed. If you need additional assistance, you can reopen the ticket at any time.
@elseif($ticket->isClosed())
This ticket has been closed. If you need further assistance, please create a new ticket or reopen this one if the issue persists.
@else
We'll continue to work on your ticket and keep you updated on any progress.
@endif

Best regards,  
{{ config('app.name') }} Support Team

@component('mail::subcopy')
If you're having trouble clicking the "View Ticket" button, copy and paste this URL into your web browser: {{ $url }}
@endcomponent
@endcomponent








