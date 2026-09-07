<div class="space-y-4">
    @foreach($payout->transactions as $transaction)
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
                    Balance: ₹{{ number_format($transaction->balance_after, 2) }}
                </p>
            </div>
        </div>
    @endforeach

    @if($payout->transactions->isEmpty())
        <p class="text-sm text-gray-500 text-center">
            No transactions found
        </p>
    @endif
</div>








