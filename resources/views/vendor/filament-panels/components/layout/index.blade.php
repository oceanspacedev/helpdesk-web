@php
    use Filament\Support\Enums\Width;

    $livewire ??= null;
    $hasTopbar = filament()->hasTopbar();
    $hasNavigation = filament()->hasNavigation();
    $renderHookScopes = $livewire?->getRenderHookScopes();
    $maxContentWidth ??= (filament()->getMaxContentWidth() ?? Width::SevenExtraLarge);

    if (is_string($maxContentWidth)) {
        $maxContentWidth = Width::tryFrom($maxContentWidth) ?? $maxContentWidth;
    }
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="flex h-screen overflow-hidden bg-gray-50 fi-layout dark:bg-gray-950" x-data @keydown.window.escape="$store.sidebar.close()">
        @if ($hasNavigation)
            @persist('sidebar')
                @livewire(filament()->getSidebarLivewireComponent())
            @endpersist
        @endif

        <div class="fi-main-ctn flex w-0 flex-1 flex-col overflow-hidden bg-white ring-1 ring-gray-200 lg:my-2 lg:rounded-tl-xl lg:rounded-bl-xl dark:bg-gray-900 dark:ring-white/20">
            <div class="flex flex-1 flex-col justify-between overflow-hidden overflow-y-auto">
                @if ($hasTopbar)
                    @livewire(filament()->getTopbarLivewireComponent())
                @endif

                <main
                    @class([
                        'fi-main mky-main flex-1',
                        ($maxContentWidth instanceof \BackedEnum) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                    ])
                >
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_START, scopes: $renderHookScopes) }}

                    <div {{ $attributes->twMerge(['class' => 'flex-1 min-h-full']) }}>
                        {{ $slot }}
                    </div>

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_END, scopes: $renderHookScopes) }}
                </main>

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
