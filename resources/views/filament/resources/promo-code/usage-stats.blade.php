<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div class="bg-gray-50 p-4 rounded-lg">
            <div class="text-sm font-medium text-gray-500">Total Uses</div>
            <div class="text-2xl font-bold text-gray-900">{{ $promoCode->usages()->count() }}</div>
        </div>
        
        <div class="bg-gray-50 p-4 rounded-lg">
            <div class="text-sm font-medium text-gray-500">Total Discount</div>
            <div class="text-2xl font-bold text-green-600">₹{{ number_format($promoCode->usages()->sum('discount_amount'), 2) }}</div>
        </div>
    </div>

    @if($promoCode->max_uses_total)
        <div class="bg-blue-50 p-4 rounded-lg">
            <div class="text-sm font-medium text-blue-700">Usage Limit</div>
            <div class="text-lg font-semibold text-blue-900">
                {{ $promoCode->usages()->count() }} / {{ $promoCode->max_uses_total }}
            </div>
            <div class="mt-2">
                <div class="w-full bg-blue-200 rounded-full h-2">
                    <div class="bg-blue-600 h-2 rounded-full" style="width: {{ min(100, ($promoCode->usages()->count() / $promoCode->max_uses_total) * 100) }}%"></div>
                </div>
            </div>
        </div>
    @endif

    @if($promoCode->expires_at)
        <div class="bg-yellow-50 p-4 rounded-lg">
            <div class="text-sm font-medium text-yellow-700">Expires</div>
            <div class="text-lg font-semibold text-yellow-900">
                {{ $promoCode->expires_at->format('M j, Y g:i A') }}
            </div>
            <div class="text-sm text-yellow-600">
                {{ $promoCode->expires_at->diffForHumans() }}
            </div>
        </div>
    @endif
</div>








