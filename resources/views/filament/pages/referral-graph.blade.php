<x-filament::page>
    <div class="space-y-4">
        <div class="flex items-end space-x-2">
            <div class="w-72">
                <x-filament::input.wrapper>
                    <x-filament::input type="number" wire:model.defer="userId" placeholder="Enter User ID" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button wire:click="loadGraph">Load Graph</x-filament::button>
        </div>

        @if (!empty($tree))
            <div class="p-4 bg-white shadow rounded">
                <h3 class="font-semibold mb-2">Referral Tree</h3>
                <div class="text-sm text-gray-700">
                    @php
                        $render = function ($node) use (&$render) {
                            echo '<div class="ml-4 border-l pl-3">';
                            echo '<div class="mb-1">';
                            echo '<span class="font-medium">#' . e($node['id']) . '</span> ' . e($node['name']);
                            echo ' <span class="text-gray-500">(' . e($node['phone']) . ')</span>';
                            echo ' <span class="text-blue-600">[' . e($node['referral_code']) . ']</span>';
                            echo '</div>';
                            if (!empty($node['children'])) {
                                foreach ($node['children'] as $child) {
                                    $render($child);
                                }
                            }
                            echo '</div>';
                        };
                    @endphp
                    {!! $render($tree) !!}
                </div>
            </div>
        @endif
    </div>
</x-filament::page>
