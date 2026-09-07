<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    @push('styles')
    <style>
        /* Message column with ellipsis truncation */
        .support-chat-message-cell {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 300px;
            display: inline-block;
            vertical-align: top;
        }

        /* Tooltip wrapping for long messages */
        [data-tooltip],
        .tippy-box,
        .tippy-content,
        [x-tooltip] + [role="tooltip"],
        [role="tooltip"] {
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
            word-break: break-word !important;
            white-space: normal !important;
            max-width: 500px !important;
            line-height: 1.5 !important;
        }

        .tippy-content,
        [role="tooltip"] .tippy-content {
            white-space: pre-wrap !important;
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
            max-width: 500px !important;
            padding: 8px 12px !important;
        }

        /* Ensure tooltip box can expand for wrapped content */
        .tippy-box {
            max-width: 500px !important;
        }
    </style>
    @endpush

    <div class="flex flex-col gap-y-6">
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>

