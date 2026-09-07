<div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-4 mt-4">
    <div class="flex items-start gap-3">
        <x-heroicon-o-information-circle class="w-5 h-5 text-primary-600 dark:text-primary-400 flex-shrink-0 mt-0.5" />
        <div class="flex-1">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">
                Fare Calculation Formula
            </h4>
            <div class="text-sm text-gray-700 dark:text-gray-300 space-y-2">
                <p class="font-medium">The total fare is calculated using the following formula:</p>
                <div class="bg-white dark:bg-gray-900 rounded-md p-3 border border-gray-200 dark:border-gray-700 font-mono text-sm">
                    <div class="space-y-1">
                        <div><strong>Step 1:</strong> Calculate Extra Distance</div>
                        <div class="ml-4 text-gray-600 dark:text-gray-400">
                            Extra Distance = max(0, Total Distance - Base Distance)
                        </div>
                        <div class="mt-2"><strong>Step 2:</strong> Calculate Distance Fare</div>
                        <div class="ml-4 text-gray-600 dark:text-gray-400">
                            Distance Fare = Extra Distance × Price per KM
                        </div>
                        <div class="mt-2"><strong>Step 3:</strong> Calculate Time Fare</div>
                        <div class="ml-4 text-gray-600 dark:text-gray-400">
                            Time Fare = Travel Time (minutes) × Price per Minute
                        </div>
                        <div class="mt-2"><strong>Step 4:</strong> Calculate Subtotal</div>
                        <div class="ml-4 text-gray-600 dark:text-gray-400">
                            Subtotal = Base Price + Distance Fare + Time Fare
                        </div>
                        <div class="mt-2"><strong>Step 5:</strong> Add Waiting Charges (if applicable)</div>
                        <div class="ml-4 text-gray-600 dark:text-gray-400">
                            Waiting Time = max(0, Actual Waiting Time - Free Waiting Time)
                        </div>
                        <div class="ml-4 text-gray-600 dark:text-gray-400">
                            Waiting Charge = Waiting Time × Waiting Charge per Minute
                        </div>
                        <div class="mt-2"><strong>Step 6:</strong> Calculate Final Fare</div>
                        <div class="ml-4 text-gray-600 dark:text-gray-400">
                            Final Fare = max(Minimum Fare, Subtotal + Waiting Charge)
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                    <strong>Note:</strong> The final fare will never be less than the Minimum Fare, even if the calculated amount is lower.
                </p>
            </div>
        </div>
    </div>
</div>

    