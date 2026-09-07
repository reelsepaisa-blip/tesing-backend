@props([
    'label' => '',
    'icon' => null,
    'value' => '',
])

<div class="space-y-1">
    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
        @if ($icon)
            <x-filament::icon :icon="$icon" class="w-4 h-4 text-primary-500" />
        @endif
        <span>{{ $label }}</span>
    </div>
    <div class="text-base text-gray-900 dark:text-gray-100">
        {{ $value }}
    </div>
</div>


