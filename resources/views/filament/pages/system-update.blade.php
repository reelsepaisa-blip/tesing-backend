<x-filament-panels::page>
    <!-- System Version Display -->
    <div style="text-align: center; margin-bottom: 30px; padding: 20px 0;">
        <h2 style="font-size: 18px; color: #374151; margin: 0; font-weight: 400;">
            System Version : <span style="color: #ef4444; font-weight: 600;">{{ $this->getSystemVersion() }}</span>
        </h2>
    </div>

    <!-- Filament Form -->
    <form wire:submit="save">
        {{ $this->form }}

        <!-- Save Button - Positioned on the right -->
        <div style="display: flex; justify-content: flex-end; margin-top: 24px;">
            <x-filament::button 
                type="submit" 
                color="success"
                size="md"
                wire:loading.attr="disabled"
                wire:target="save"
            >
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save" class="fi-loading fi-loading-spinner"></span>
            </x-filament::button>
        </div>
    </form>

    <style>
        /* File upload button styling to match image */
        .fi-fo-file-upload .fi-btn {
            background-color: #60a5fa !important;
            color: white !important;
            border: none !important;
        }

        .fi-fo-file-upload .fi-btn:hover {
            background-color: #3b82f6 !important;
        }

        /* Input field styling */
        .fi-input-wrp input {
            border-color: #60a5fa;
        }

        .fi-input-wrp input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
    </style>
</x-filament-panels::page>
