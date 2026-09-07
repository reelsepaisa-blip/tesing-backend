<div class="space-y-6">
    <!-- Job Information -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Job Information</h3>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Job ID:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->id }}</span>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Queue:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->queue }}</span>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Connection:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $record->connection }}</span>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Failed At:</span>
                    <span
                        class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ \Carbon\Carbon::parse($record->failed_at)->format('Y-m-d H:i:s') }}</span>
                </div>
            </div>

            @if (isset($payload['displayName']))
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Job Class:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $payload['displayName'] }}</span>
                </div>
            @endif

            @if (isset($payload['maxTries']))
                <div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Max Tries:</span>
                    <span class="text-sm text-gray-900 dark:text-gray-100 ml-2">{{ $payload['maxTries'] }}</span>
                </div>
            @endif
        </div>
    </div>

    <!-- Job Data -->
    @if (isset($payload['data']))
        <div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Job Data</h3>
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <pre class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap overflow-x-auto">{{ json_encode($payload['data'], JSON_PRETTY_PRINT) }}</pre>
            </div>
        </div>
    @endif

    <!-- Exception Details -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Exception Details</h3>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <pre class="text-sm text-red-900 dark:text-red-100 whitespace-pre-wrap overflow-x-auto">{{ $record->exception }}</pre>
        </div>
    </div>

    <!-- Full Payload (for debugging) -->
    <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Full Payload (Debug)</h3>
        <details class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <summary class="text-sm font-medium text-gray-600 dark:text-gray-400 cursor-pointer">Show Raw Payload
            </summary>
            <pre class="text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap overflow-x-auto mt-4">{{ json_encode(json_decode($record->payload), JSON_PRETTY_PRINT) }}</pre>
        </details>
    </div>
</div>
