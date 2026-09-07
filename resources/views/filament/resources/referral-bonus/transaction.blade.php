<div class="space-y-4">
    @if ($bonus->transaction)
        <div class="flex items-start space-x-4">
            <div class="flex-shrink-0">
                <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center">
                    <x-heroicon-o-banknotes class="w-5 h-5 text-green-600" />
                </div>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-900">
                        {{ $bonus->transaction->description }}
                    </p>
                    <p class="text-sm font-medium text-green-600">
                        +₹{{ number_format($bonus->transaction->amount, 2) }}
                    </p>
                </div>
                <div class="flex items-center justify-between mt-1">
                    <p class="text-sm text-gray-500">
                        {{ $bonus->transaction->type }}
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ $bonus->transaction->created_at->diffForHumans() }}
                    </p>
                </div>
                <p class="text-sm text-gray-500">
                    Balance: ₹{{ number_format($bonus->transaction->balance ?? 0, 2) }}
                </p>
            </div>
        </div>
    @else
        <div class="bg-yellow-50 p-4 rounded-lg">
            <div class="flex items-center">
                <x-heroicon-o-clock class="w-5 h-5 text-yellow-600 mr-2" />
                <div>
                    <p class="text-sm font-medium text-yellow-800">
                        Bonus not yet credited
                    </p>
                    <p class="text-sm text-yellow-600">
                        This bonus will be credited to the user's wallet when processed.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
        <div class="text-sm font-medium text-gray-700 dark:text-white mb-2">Bonus Details</div>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-600 dark:text-white">Type:</span>
                <span class="font-medium dark:text-white">{{ str($bonus->type)->title() }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600 dark:text-white">Amount:</span>
                <span class="font-medium text-green-600 dark:text-green-400">₹{{ number_format($bonus->amount, 2) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600 dark:text-white">Status:</span>
                <span @class([
                    'font-medium',
                    'text-yellow-600 dark:text-yellow-400' => $bonus->isPending(),
                    'text-green-600 dark:text-green-400' => $bonus->isCredited(),
                    'text-red-600 dark:text-red-400' => $bonus->isExpired() || $bonus->isCancelled(),
                ])>
                    {{ str($bonus->status)->title() }}
                </span>
            </div>
            @if ($bonus->expires_at)
                <div class="flex justify-between">
                    <span class="text-gray-600 dark:text-white">Expires:</span>
                    <span class="font-medium dark:text-white">{{ $bonus->expires_at->format('M j, Y g:i A') }}</span>
                </div>
            @endif
        </div>
    </div>
</div>
