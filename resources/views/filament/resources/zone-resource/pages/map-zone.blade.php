{{-- TEST FILE LOADED: {{ now() }} --}}
<x-filament::page>
    <div class="space-y-4">
        <!-- Zone Header -->
        <div class="flex justify-between items-center">
            <div>
                <h2 class="text-lg font-medium">{{ $record->name }}</h2>
                <p class="text-sm text-gray-500">{{ $record->city->name }}</p>
            </div>
            <div class="flex items-center space-x-2">
                <span @class([ 'px-2 py-1 text-xs font-medium rounded-full' , 'bg-green-100 text-green-800'=> $record->status,
                    'bg-gray-100 text-gray-800' => !$record->status,
                    ])>
                    {{ $record->status ? 'Active' : 'Inactive' }}
                </span>
                @if($record->isSurgeActive())
                <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">
                    {{ number_format($record->surge_multiplier, 2) }}x Surge
                </span>
                @endif
            </div>
        </div>

        <!-- Simple Map Container -->
        <div class="w-full h-[500px] rounded-xl border border-gray-200 shadow-sm bg-gray-50 flex items-center justify-center">
            <div class="text-center text-gray-500">
                <div class="text-lg font-medium mb-2">Zone Map</div>
                <div class="text-sm mb-4">Zone: {{ $record->name }}</div>
                <div class="text-sm">City: {{ $record->city->name }}</div>
                <div class="text-sm mt-2">Status: {{ $record->status ? 'Active' : 'Inactive' }}</div>
                @if($record->boundaries)
                <div class="text-sm mt-2">Boundaries: Configured</div>
                @else
                <div class="text-sm mt-2 text-red-500">No boundaries set</div>
                @endif
                <div class="text-sm mt-4 text-blue-600">File loaded at: {{ now() }}</div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-4 gap-4">
            <x-filament::card>
                <div class="text-sm">
                    <div class="font-medium">Active Drivers</div>
                    <div class="mt-1 text-2xl font-bold text-primary-600">
                        {{ $record->getActiveDriversCount() }}
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm">
                    <div class="font-medium">Pending Bookings</div>
                    <div class="mt-1 text-2xl font-bold text-warning-600">
                        {{ \App\Models\Booking::where('status', 'pending')->where('pickup_zone_id', $record->id)->count() }}
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm">
                    <div class="font-medium">Active Trips</div>
                    <div class="mt-1 text-2xl font-bold text-success-600">
                        {{ \App\Models\Booking::whereIn('status', ['accepted', 'started'])->where('pickup_zone_id', $record->id)->count() }}
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-sm">
                    <div class="font-medium">Average Wait Time</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">
                        {{ number_format(\App\Models\Booking::where('status', 'completed')->where('pickup_zone_id', $record->id)->avg('waiting_time') ?? 0, 1) }} min
                    </div>
                </div>
            </x-filament::card>
        </div>

        <!-- Zone Details -->
        <x-filament::card>
            <div class="text-sm">
                <div class="font-medium mb-4">Zone Details</div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <div class="font-medium text-gray-600">Name</div>
                        <div>{{ $record->name }}</div>
                    </div>
                    <div>
                        <div class="font-medium text-gray-600">City</div>
                        <div>{{ $record->city->name }}</div>
                    </div>
                    <div>
                        <div class="font-medium text-gray-600">Status</div>
                        <div>{{ $record->status ? 'Active' : 'Inactive' }}</div>
                    </div>
                    <div>
                        <div class="font-medium text-gray-600">Surge Multiplier</div>
                        <div>{{ number_format($record->surge_multiplier, 2) }}x</div>
                    </div>
                    @if($record->surge_start_time)
                    <div>
                        <div class="font-medium text-gray-600">Surge Start</div>
                        <div>{{ $record->surge_start_time->format('M d, Y H:i') }}</div>
                    </div>
                    @endif
                    @if($record->surge_end_time)
                    <div>
                        <div class="font-medium text-gray-600">Surge End</div>
                        <div>{{ $record->surge_end_time->format('M d, Y H:i') }}</div>
                    </div>
                    @endif
                </div>
                @if($record->description)
                <div class="mt-4">
                    <div class="font-medium text-gray-600">Description</div>
                    <div>{{ $record->description }}</div>
                </div>
                @endif
            </div>
        </x-filament::card>
    </div>
</x-filament::page>