<div class="space-y-6">
    <!-- User Information -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">User Information</h3>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">User:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->user->name ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Email:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->user->email ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Information -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Booking Information</h3>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Booking Code:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->booking->booking_code ?? 'N/A' }}</span>
                </div>
                <div>
                    {{-- <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Booking Status:</span> --}}
                    {{-- <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->booking->status ?? 'N/A' }}</span> --}}
                </div>
            </div>
        </div>
    </div>

    <!-- Promo Details -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Promo Details</h3>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Promo Code:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->promoCode->code ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Description:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->promoCode->description ?? 'N/A' }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Amount Details -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Amount Details</h3>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Original Amount:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">₹{{ number_format($record->original_amount ?? 0, 2) }}</span>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Discount Amount:</span>
                    <span class="text-sm text-green-600 dark:text-green-400 ml-2">₹{{ number_format($record->discount_amount ?? 0, 2) }}</span>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Final Amount:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">₹{{ number_format($record->final_amount ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Information -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Additional Information</h3>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
            <div class="grid grid-cols-2 gap-4">
                {{-- <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Status:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->status ?? 'N/A' }}</span>
                </div> --}}
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Created At:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->created_at ? $record->created_at->format('Y-m-d H:i:s') : 'N/A' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

