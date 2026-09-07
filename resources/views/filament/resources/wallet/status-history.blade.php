<div class="space-y-4">
    @php
        $history = collect($getRecord()->meta_data ?? [])
            ->filter(fn ($value, $key) => str_ends_with($key, '_at'))
            ->map(fn ($value, $key) => [
                'type' => str_replace('_at', '', $key),
                'date' => \Carbon\Carbon::parse($value),
                'reason' => $getRecord()->meta_data[str_replace('_at', '_reason', $key)] ?? null,
            ])
            ->sortByDesc('date')
            ->values();
    @endphp

    @foreach($history as $event)
        <div class="flex items-start space-x-4">
            <div class="flex-shrink-0">
                <div @class([
                    'w-8 h-8 rounded-full flex items-center justify-center',
                    'bg-green-100' => in_array($event['type'], ['unblocked', 'unsuspended']),
                    'bg-red-100' => $event['type'] === 'blocked',
                    'bg-yellow-100' => $event['type'] === 'suspended',
                ])>
                    <x-dynamic-component
                        :component="'heroicon-o-' . match($event['type']) {
                            'blocked' => 'lock-closed',
                            'unblocked' => 'lock-open',
                            'suspended' => 'pause',
                            'unsuspended' => 'play',
                            default => 'heroicon-o-information-circle',
                        }"
                        @class([
                            'w-5 h-5',
                            'text-green-600' => in_array($event['type'], ['unblocked', 'unsuspended']),
                            'text-red-600' => $event['type'] === 'blocked',
                            'text-yellow-600' => $event['type'] === 'suspended',
                        ])
                    />
                </div>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-900">
                        {{ str($event['type'])->title() }}
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ $event['date']->diffForHumans() }}
                    </p>
                </div>
                @if($event['reason'])
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $event['reason'] }}
                    </p>
                @endif
            </div>
        </div>
    @endforeach

    @if($history->isEmpty())
        <p class="text-sm text-gray-500 text-center">
            No status changes recorded
        </p>
    @endif
</div>








