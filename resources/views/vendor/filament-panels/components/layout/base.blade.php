@props([
    'livewire' => null,
])

@php
    $renderHookScopes = $livewire?->getRenderHookScopes();
@endphp

<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ __('filament-panels::layout.direction') ?? 'ltr' }}"
    @class([
        'fi',
        'dark' => filament()->hasDarkModeForced(),
    ])
>
    <head>
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::HEAD_START, scopes: $renderHookScopes) }}

        <meta charset="utf-8" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        @if ($favicon = filament()->getFavicon())
            <link rel="icon" href="{{ $favicon }}" />
        @endif

        @php
            $title = trim(strip_tags($livewire?->getTitle() ?? ''));
            $brandName = trim(strip_tags(filament()->getBrandName()));
        @endphp

        <title>
            {{ filled($title) ? $title : null }}
            {{ filled($brandName) && filled($title) ? ' - ' : null }}
            {{ filled($brandName) ? $brandName : null }}
        </title>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::STYLES_BEFORE, scopes: $renderHookScopes) }}

        <style>
            [x-cloak=''],
            [x-cloak='x-cloak'],
            [x-cloak='1'] {
                display: none !important;
            }

            [x-cloak='inline-flex'] {
                display: inline-flex !important;
            }

            @media (max-width: 1023px) {
                [x-cloak='-lg'] {
                    display: none !important;
                }
            }

            @media (min-width: 1024px) {
                [x-cloak='lg'] {
                    display: none !important;
                }
            }
        </style>

        @filamentStyles

        {{ filament()->getTheme()->getHtml() }}
        {{ filament()->getFontPreloadHtml() }}
        {{ filament()->getMonoFontPreloadHtml() }}
        {{ filament()->getSerifFontPreloadHtml() }}
        {{ filament()->getFontHtml() }}
        {{ filament()->getMonoFontHtml() }}
        {{ filament()->getSerifFontHtml() }}

        <style>
            :root {
                --font-family: '{!! filament()->getFontFamily() !!}';
                --mono-font-family: '{!! filament()->getMonoFontFamily() !!}';
                --serif-font-family: '{!! filament()->getSerifFontFamily() !!}';
                --sidebar-width: {{ filament()->getSidebarWidth() }};
                --collapsed-sidebar-width: {{ filament()->getCollapsedSidebarWidth() }};
                --default-theme-mode: {{ filament()->getDefaultThemeMode()->value }};
            }
        </style>

        @stack('styles')

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::STYLES_AFTER, scopes: $renderHookScopes) }}

        @if (! filament()->hasDarkMode())
            <script>
                localStorage.setItem('theme', 'light')
            </script>
        @elseif (filament()->hasDarkModeForced())
            <script>
                localStorage.setItem('theme', 'dark')
            </script>
        @else
            <script>
                const loadDarkMode = () => {
                    window.theme = localStorage.getItem('theme') ?? @js(filament()->getDefaultThemeMode()->value)

                    if (
                        window.theme === 'dark' ||
                        (window.theme === 'system' &&
                            window.matchMedia('(prefers-color-scheme: dark)')
                                .matches)
                    ) {
                        document.documentElement.classList.add('dark')
                    }
                }

                loadDarkMode()

                document.addEventListener('livewire:navigated', loadDarkMode)
            </script>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::HEAD_END, scopes: $renderHookScopes) }}
    </head>

    <body
        {{
            $attributes
                ->merge($livewire?->getExtraBodyAttributes() ?? [], escape: false)
                ->class([
                    'fi-body',
                    'fi-panel-' . filament()->getId(),
                ])
        }}
    >
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::BODY_START, scopes: $renderHookScopes) }}

        {{-- Must run first: register Filament Alpine components so they exist before Livewire hydrates --}}
        <script>
            (function () {
                function registerFilamentAlpineFallbacks() {
                    if (typeof window.Alpine === 'undefined' || typeof window.Alpine.data !== 'function') return;
                    if (typeof window.Alpine.data('filamentSchema') === 'undefined') {
                        window.Alpine.data('filamentSchema', function (opts) {
                            var livewireId = (opts && opts.livewireId) || '';
                            return {
                                handleFormValidationError: function (e) { if (e.detail && e.detail.livewireId !== livewireId) return; this.$nextTick && this.$nextTick(function () {}); },
                                isStateChanged: function (state, old) { if (state === undefined) return false; try { return JSON.stringify(state) !== JSON.stringify(old); } catch (e) { return state !== old; } }
                            };
                        });
                    }
                    if (typeof window.Alpine.data('filamentSchemaComponent') === 'undefined') {
                        window.Alpine.data('filamentSchemaComponent', function (opts) {
                            var path = (opts && opts.path) || '', containerPath = (opts && opts.containerPath) || '', $wire = (opts && opts.$wire) || {};
                            function resolvePath(cont, p, isAbs) {
                                if (p && p.indexOf('/') === 0) return p.slice(1);
                                if (isAbs) return p || '';
                                if (!p) return cont || '';
                                return cont ? cont + '.' + p : p;
                            }
                            return {
                                $statePath: path,
                                $get: function (p, isAbsolute) { return $wire.$get ? $wire.$get(resolvePath(containerPath, p, isAbsolute)) : undefined; },
                                $set: function (p, state, isAbsolute, isLive) { return $wire.$set ? $wire.$set(resolvePath(containerPath, p, isAbsolute), state, isLive) : undefined; },
                                get $state() { return $wire.$get ? $wire.$get(path) : undefined; }
                            };
                        });
                    }
                    if (typeof window.Alpine.data('filamentFormButton') === 'undefined') {
                        window.Alpine.data('filamentFormButton', function () {
                            var self; return {
                                form: null, isProcessing: false, processingMessage: null,
                                init: function () { self = this; var formEl = this.$el && this.$el.closest && this.$el.closest('form');
                                    if (formEl) {
                                        formEl.addEventListener('form-processing-started', function (e) { self.isProcessing = true; self.processingMessage = (e.detail && e.detail.message) || null; });
                                        formEl.addEventListener('form-processing-finished', function () { self.isProcessing = false; });
                                    }
                                }
                            };
                        });
                    }
                }
                if (window.Alpine && typeof window.Alpine.data === 'function') registerFilamentAlpineFallbacks();
                else document.addEventListener('alpine:init', registerFilamentAlpineFallbacks);
            })();
        </script>

        {{ $slot }}

        @livewire(Filament\Livewire\Notifications::class)

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SCRIPTS_BEFORE, scopes: $renderHookScopes) }}

        @stack('scripts-before')

        @filamentScripts(withCore: true)

        @if (filament()->hasBroadcasting() && config('filament.broadcasting.echo'))
            <script data-navigate-once>
                window.Echo = new window.EchoFactory(@js(config('filament.broadcasting.echo')))

                window.dispatchEvent(new CustomEvent('EchoLoaded'))
            </script>
        @endif

        @if (filament()->hasDarkMode() && (! filament()->hasDarkModeForced()))
            <script>
                loadDarkMode()
            </script>
        @endif

        @stack('scripts')

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SCRIPTS_AFTER, scopes: $renderHookScopes) }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::BODY_END, scopes: $renderHookScopes) }}
    </body>
</html>
