@props([
    'icon',
    'title',
    'description' => null,
    'backUrl' => null,
    'backLabel' => 'Kembali ke halaman masuk',
])

@php
    use Filament\Support\Icons\Heroicon;
    use Illuminate\View\ComponentAttributeBag;
    use function Filament\Support\generate_icon_html;
@endphp

<header class="flex flex-col items-center justify-center py-3">
    <div class="flex items-center justify-center space-y-2 rounded-lg bg-white p-2 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700/80">
        {{
            generate_icon_html(
                $icon,
                attributes: (new ComponentAttributeBag)->class(['size-5']),
            )
        }}
    </div>

    <h1 class="mt-4 font-heading text-lg font-medium text-gray-950 dark:text-white">
        {{ $title }}
    </h1>

    @if (filled($description))
        <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">
            {{ $description }}
        </p>
    @endif

    @if (filled($backUrl))
        <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">
            <a
                href="{{ $backUrl }}"
                class="fi-ac-link-action fi-link fi-size-md fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-300"
            >
                {{
                    generate_icon_html(
                        Heroicon::ArrowLeft,
                        attributes: (new ComponentAttributeBag)->class(['fi-icon', 'fi-size-md']),
                    )
                }}
                {{ $backLabel }}
            </a>
        </p>
    @endif
</header>
