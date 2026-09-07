<div class="flex items-center gap-2">
    <span class="text-gray-900 dark:text-gray-100 font-medium">{{ $label }}</span>
    <div x-data="{ showTooltip: false }" class="relative inline-flex" @mouseenter="showTooltip = true"
        @mouseleave="showTooltip = false">
        <x-heroicon-o-information-circle
            class="w-6 h-6 text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 cursor-help transition-colors" />
        <div x-show="showTooltip" x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95" x-cloak
            class="absolute left-0 bottom-full mb-3 w-[42rem] max-w-[calc(100vw-2rem)] z-[99999]"
            style="min-width: 500px;text-align:center !important;z-index: 99999 !important;"
            @mouseenter="showTooltip = true" @mouseleave="showTooltip = false">
            <div class="bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm rounded-lg shadow-2xl p-5 border-2 border-gray-200 dark:border-gray-700 relative text-left"
                style="text-align:center !important;">
                <h4
                    class="font-semibold mb-3 text-base text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3">
                    Fare Calculation Formula</h4>
                <div class="space-y-2 text-gray-700 dark:text-gray-300">
                    <div style="margin-top: 10px !important;"><strong class="text-gray-900 dark:text-white">Step
                            1:</strong> Calculate Extra Distance</div>
                    <div class="ml-4 font-mono text-sm text-gray-900 dark:text-gray-200">Extra Distance = max(0, Total
                        Distance - Base Distance)</div>
                    <div class="mt-2"><strong class="text-gray-900 dark:text-white">Step 2:</strong> Calculate
                        Distance Fare</div>
                    <div class="ml-4 font-mono text-sm text-gray-900 dark:text-gray-200">Distance Fare = Extra Distance
                        × Price per KM</div>
                    <div class="mt-2"><strong class="text-gray-900 dark:text-white">Step 3:</strong> Calculate Time
                        Fare</div>
                    <div class="ml-4 font-mono text-sm text-gray-900 dark:text-gray-200">Time Fare = Travel Time
                        (minutes) × Price per Minute</div>
                    <div class="mt-2"><strong class="text-gray-900 dark:text-white">Step 4:</strong> Calculate
                        Subtotal</div>
                    <div class="ml-4 font-mono text-sm text-gray-900 dark:text-gray-200">Subtotal = Base Price +
                        Distance Fare + Time Fare</div>
                    <div class="mt-2"><strong class="text-gray-900 dark:text-white">Step 5:</strong> Add Waiting
                        Charges (if applicable)</div>
                    <div class="ml-4 font-mono text-sm text-gray-900 dark:text-gray-200">Waiting Time = max(0, Actual
                        Waiting Time - Free Waiting Time)</div>
                    <div class="ml-4 font-mono text-sm text-gray-900 dark:text-gray-200">Waiting Charge = Waiting Time ×
                        Waiting Charge per Minute</div>
                    <div class="mt-2"><strong class="text-gray-900 dark:text-white">Step 6:</strong> Calculate Final
                        Fare</div>
                    <div class="ml-4 font-mono text-sm text-gray-900 dark:text-gray-200">Final Fare = max(Minimum Fare,
                        Subtotal + Waiting Charge)</div>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-3 pt-3 border-t border-gray-200 dark:border-gray-700"
                    style="margin: 10px 0 10px 0 !important;">
                    <strong>Note:</strong> The final fare will never be less than the Minimum Fare.
                </p>
                <div
                    class="absolute left-8 top-full w-0 h-0 border-l-[8px] border-r-[8px] border-t-[8px] border-transparent border-t-white dark:border-t-gray-800 shadow-lg">
                </div>
            </div>
        </div>
    </div>
</div>
