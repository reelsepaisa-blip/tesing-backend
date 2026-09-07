<x-filament::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="flex justify-end mt-6">
            <x-filament::button
                type="submit"
                size="lg"
            >
                Save Changes
            </x-filament::button>
        </div>
    </form>
</x-filament::page>








