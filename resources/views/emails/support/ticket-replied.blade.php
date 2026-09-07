@component('mail::message')
# New Reply on Support Ticket

Dear {{ $notifiable->name }},

{{ $message->user->name }} has replied to your support ticket:

**Ticket Number:** {{ $ticket->ticket_number }}  
**Subject:** {{ $ticket->subject }}  
**Status:** {{ ucfirst($ticket->status) }}

### Reply Preview:
{{ str()->limit(strip_tags($message->message), 200) }}

@if($message->attachments->count() > 0)
**Attachments:** {{ $message->attachments->count() }} file(s) attached
@endif

@component('mail::button', ['url' => $url])
View Full Reply
@endcomponent

@if($isAdmin)
Please review and respond to this ticket as soon as possible.
@else
Our support team will review your message and respond if needed.
@endif

Best regards,  
{{ config('app.name') }} Support Team

@component('mail::subcopy')
If you're having trouble clicking the "View Full Reply" button, copy and paste this URL into your web browser: {{ $url }}
@endcomponent
@endcomponent








