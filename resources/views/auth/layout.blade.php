@php
    if (filament()->getCurrentPanel() === null) {
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->bootCurrentPanel();
    }
@endphp

<x-filament-panels::layout.base>
    <div class="fi-simple-layout">
        <div class="fi-simple-main-ctn">
            <main class="fi-simple-main fi-width-full">
                <x-mekaya::auth-card>
                    @yield('content')
                </x-mekaya::auth-card>
            </main>
        </div>
    </div>
</x-filament-panels::layout.base>
