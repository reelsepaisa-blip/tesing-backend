<div class="flex items-center justify-between w-full">
    <label class="flex items-center gap-x-2">
        <input 
            type="checkbox" 
            wire:model.lazy="data.remember"
            class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm outline-none transition duration-75 focus:ring-2 focus:ring-primary-600 disabled:pointer-events-none disabled:bg-gray-50 disabled:text-gray-50 dark:border-white/10 dark:bg-white/5 dark:checked:border-primary-500 dark:checked:bg-primary-500 dark:focus:ring-primary-500 dark:disabled:border-white/15 dark:disabled:bg-white/10"
        >
        <span class="text-sm font-medium text-gray-950 dark:text-white">
            {{ $rememberLabel }}
        </span>
    </label>
    
    @if($forgotPasswordUrl)
        <a href="{{ $forgotPasswordUrl }}" class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
            {{ $forgotPasswordLabel }}
        </a>
    @endif
</div>

