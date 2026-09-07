<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Demo Mode Control
        </x-slot>

        <x-slot name="description">
            Enable or disable demo mode to protect your demo data from modifications.
        </x-slot>

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Demo Mode
                    </label>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        When enabled, all create, update, and delete operations are blocked to protect demo data.
                    </p>
                </div>
                <button
                    wire:click="toggleDemoMode"
                    type="button"
                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 {{ $demoMode ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700' }}"
                    role="switch"
                    aria-checked="{{ $demoMode ? 'true' : 'false' }}"
                >
                    <span
                        aria-hidden="true"
                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $demoMode ? 'translate-x-5' : 'translate-x-0' }}"
                    ></span>
                </button>
            </div>

            @if($demoMode)
                <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                Demo Mode is Active
                            </h3>
                            <div class="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                                <p>All create, update, and delete operations are currently blocked. Users can view data but cannot modify it.</p>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-green-800 dark:text-green-200">
                                Demo Mode is Inactive
                            </h3>
                            <div class="mt-2 text-sm text-green-700 dark:text-green-300">
                                <p>Normal operations are enabled. All users can create, update, and delete records.</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

