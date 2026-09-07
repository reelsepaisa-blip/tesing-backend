<div class="space-y-4">
    @php
        // Get all transactions (both directly linked and via booking_id)
        $directTransactions = $commission->transactions;
        
        // Get transactions linked via booking_id in meta_data (for backward compatibility)
        $bookingTransactions = \App\Models\WalletTransaction::where('type', \App\Models\WalletTransaction::TYPE_DRIVER_COMMISSION)
            ->get()
            ->filter(function($transaction) use ($commission) {
                $metaData = $transaction->meta_data ?? [];
                return isset($metaData['booking_id']) && $metaData['booking_id'] == $commission->booking_id;
            });
        
        $allTransactions = $directTransactions->merge($bookingTransactions)->unique('id');
    @endphp
    @foreach($allTransactions as $transaction)
        <div class="flex items-start space-x-4">
            <div class="flex-shrink-0">
                <div @class([
                    'w-8 h-8 rounded-full flex items-center justify-center',
                    'bg-green-100' => $transaction->isCredit(),
                    'bg-red-100' => $transaction->isDebit(),
                ])>
                    <x-dynamic-component
                        :component="'heroicon-o-' . ($transaction->isCredit() ? 'arrow-down-left' : 'arrow-up-right')"
                        @class([
                            'w-5 h-5',
                            'text-green-600' => $transaction->isCredit(),
                            'text-red-600' => $transaction->isDebit(),
                        ])
                    />
                </div>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-900">
                        {{ $transaction->description }}
                    </p>
                    <p @class([
                        'text-sm font-medium',
                        'text-green-600' => $transaction->isCredit(),
                        'text-red-600' => $transaction->isDebit(),
                    ])>
                        {{ $transaction->isCredit() ? '+' : '-' }}₹{{ number_format(abs($transaction->amount), 2) }}
                    </p>
                </div>
                <div class="flex items-center justify-between mt-1">
                    <p class="text-sm text-gray-500">
                        {{ $transaction->type }}
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ $transaction->created_at->diffForHumans() }}
                    </p>
                </div>
                <p class="text-sm text-gray-500">
                    Balance: ₹{{ number_format($transaction->balance, 2) }}
                </p>
            </div>
        </div>
    @endforeach

    @if($allTransactions->isEmpty())
        <p class="text-sm text-gray-500 text-center">
            No transactions found
        </p>
    @endif
</div>








