<div class="space-y-4">
    @if ($getRecord())
        @php
            $activities = $getRecord()->activities()->with('user')->latest()->get();
            $messageIds = $activities
                ->where('type', 'message')
                ->pluck('meta_data')
                ->filter(fn($meta) => isset($meta['message_id']))
                ->pluck('message_id')
                ->unique()
                ->values()
                ->toArray();
            $messages = !empty($messageIds)
                ? \App\Models\SupportMessage::whereIn('id', $messageIds)->get()->keyBy('id')
                : collect();
        @endphp
        @foreach ($activities as $activity)
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <div @class([
                        'w-8 h-8 rounded-full flex items-center justify-center',
                        'bg-gray-100' => $activity->type === 'created',
                        'bg-blue-100' => $activity->type === 'updated',
                        'bg-indigo-100' => $activity->type === 'message',
                        'bg-purple-100' => $activity->type === 'attachment',
                        'bg-pink-100' => $activity->type === 'assigned',
                        'bg-yellow-100' => $activity->type === 'status',
                        'bg-orange-100' => $activity->type === 'priority',
                        'bg-green-100' => $activity->type === 'resolved',
                        'bg-red-100' => $activity->type === 'reopened',
                        'bg-gray-100' => $activity->type === 'closed',
                    ])>
                        <x-dynamic-component :component="'heroicon-o-' .
                            match ($activity->type) {
                                'created' => 'plus-circle',
                                'updated' => 'pencil',
                                'message' => 'chat-bubble-left-ellipsis',
                                'attachment' => 'paper-clip',
                                'assigned' => 'user',
                                'status' => 'arrow-path',
                                'priority' => 'exclamation-triangle',
                                'resolved' => 'check-circle',
                                'reopened' => 'arrow-uturn-left',
                                'closed' => 'x-circle',
                                default => 'heroicon-o-information-circle',
                            }" @class([
                                'w-5 h-5',
                                'text-gray-600' => $activity->type === 'created',
                                'text-blue-600' => $activity->type === 'updated',
                                'text-indigo-600' => $activity->type === 'message',
                                'text-purple-600' => $activity->type === 'attachment',
                                'text-pink-600' => $activity->type === 'assigned',
                                'text-yellow-600' => $activity->type === 'status',
                                'text-orange-600' => $activity->type === 'priority',
                                'text-green-600' => $activity->type === 'resolved',
                                'text-red-600' => $activity->type === 'reopened',
                                'text-gray-600' => $activity->type === 'closed',
                            ]) />
                    </div>
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium text-gray-900">
                            {{ $activity->user->name }}
                        </p>
                        <p class="text-sm text-gray-500">
                            {{ $activity->created_at->diffForHumans() }}
                        </p>
                    </div>
                    <p class="text-sm text-gray-500">
                        @if ($activity->type === 'message' && isset($activity->meta_data['message_id']))
                            @php
                                $message = $messages[$activity->meta_data['message_id']] ?? null;
                            @endphp
                            @if ($message)
                                {{ trim(html_entity_decode(strip_tags($message->message), ENT_QUOTES | ENT_HTML5, 'UTF-8')) }}
                            @else
                                {{ $activity->description }}
                            @endif
                        @else
                            {{ $activity->description }}
                        @endif
                    </p>

                    @if ($activity->meta_data)
                        <div class="mt-2 text-sm">
                            @foreach ($activity->meta_data as $key => $value)
                                @if (is_string($value))
                                    <p class="text-gray-600">
                                        <span class="font-medium">{{ str($key)->title() }}:</span>
                                        {{ $value }}
                                    </p>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    @else
        <div class="text-center py-8 text-gray-500">
            <p>No activities yet. Activities will appear here once the ticket is created.</p>
        </div>
    @endif
</div>
