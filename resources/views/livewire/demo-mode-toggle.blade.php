<div class="fi-user-menu-demo-toggle" x-data x-on:demo-mode-toggled.window="setTimeout(() => window.location.reload(), 1000)">
    <div class="fi-dropdown-list-item flex items-center justify-between gap-3 px-3 py-2 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors duration-75">
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <svg class="h-5 w-5 text-gray-400 dark:text-gray-500 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M2.5 4A1.5 1.5 0 001 5.5V6h18v-.5A1.5 1.5 0 0017.5 4h-15zM19 8.5H1v6A1.5 1.5 0 002.5 16h15a1.5 1.5 0 001.5-1.5v-6zM3 13.25a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zm4.75-.75a.75.75 0 000 1.5h3.5a.75.75 0 000-1.5h-3.5z" clip-rule="evenodd" />
            </svg>
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200 truncate">
                Demo Mode
            </span>
        </div>
        <button
            wire:click="toggle"
            type="button"
            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 {{ $demoMode ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700' }}"
            role="switch"
            aria-checked="{{ $demoMode ? 'true' : 'false' }}"
            aria-label="Toggle Demo Mode"
        >
            <span
                aria-hidden="true"
                class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $demoMode ? 'translate-x-5' : 'translate-x-0' }}"
            ></span>
        </button>
    </div>
</div>
