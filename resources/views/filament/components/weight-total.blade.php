<div class="flex items-center space-x-2">
    <span class="text-sm font-medium">Total Weight:</span>
    <span class="text-lg font-bold {{ $isValid ? 'text-green-600' : 'text-red-600' }}">
        {{ number_format($total, 1) }}%
    </span>
    @if ($isValid)
        <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                clip-rule="evenodd"></path>
        </svg>
    @else
        <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                clip-rule="evenodd"></path>
        </svg>
    @endif
</div>
@if (!$isValid)
    <p class="text-sm text-red-600 mt-1">
        Weights must sum to exactly 100% to save the configuration.
    </p>
@endif
