<div class="space-y-4">
    @foreach($promoCode->usages()->latest()->limit(5)->get() as $usage)
        <div class="flex items-start space-x-4">
            <div class="flex-shrink-0">
                <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center">
                    <x-heroicon-o-ticket class="w-5 h-5 text-green-600" />
                </div>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-900">
                        {{ $usage->user->name }}
                    </p>
                    <p class="text-sm font-medium text-green-600">
                        -₹{{ number_format($usage->discount_amount, 2) }}
                    </p>
                </div>
                <div class="flex items-center justify-between mt-1">
                    <p class="text-sm text-gray-500">
                        Booking #{{ $usage->booking->booking_code }}
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ $usage->created_at->diffForHumans() }}
                    </p>
                </div>
                <p class="text-sm text-gray-500">
                    ₹{{ number_format($usage->original_amount, 2) }} → ₹{{ number_format($usage->final_amount, 2) }}
                </p>
            </div>
        </div>
    @endforeach

    @if($promoCode->usages()->count() > 5)
        <div class="mt-4 text-center">
            <a href="#" class="text-sm text-primary-600 hover:text-primary-500">
                View all usage
            </a>
        </div>
    @endif

    @if($promoCode->usages()->isEmpty())
        <p class="text-sm text-gray-500 text-center">
            No usage recorded yet
        </p>
    @endif
</div>








