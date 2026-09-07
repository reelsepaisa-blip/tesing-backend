<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end space-x-3">
            <x-filament::button type="button" color="warning" wire:click="resetSettings"
                wire:confirm="Are you sure you want to reset all settings to default values?" wire:loading.attr="disabled"
                wire:target="resetSettings" style="margin-right: 10px;">
                <span wire:loading.remove wire:target="resetSettings">Reset</span>
                {{-- <span wire:loading wire:target="resetSettings" class="flex items-center">
                    <svg class="animate-spin h-4 w-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    Resetting...
                </span> --}}
            </x-filament::button>
            <x-filament::button type="submit" color="success" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Update Payment Settings</span>
                {{-- <span wire:loading wire:target="save" class="flex items-center">
                    <svg class="animate-spin h-4 w-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    Saving...
                </span> --}}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
