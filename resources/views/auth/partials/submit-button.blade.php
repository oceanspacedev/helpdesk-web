@props([
    'label',
])

<div class="fi-grid fi-sc fi-sc-has-gap mt-6" style="--cols-default: repeat(1, minmax(0, 1fr));">
    <div class="fi-grid-col" style="--col-span-default: span 1 / span 1;">
        <div class="fi-sc-component">
            <div class="fi-sc-actions">
                <div class="fi-ac fi-width-full">
                    <button
                        type="submit"
                        class="fi-ac-btn-action fi-btn fi-size-md fi-color fi-color-primary fi-bg-color-600 hover:fi-bg-color-500 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-0 hover:fi-text-color-0 dark:fi-text-color-0 dark:hover:fi-text-color-0 w-full"
                    >
                        <span class="fi-btn-label">{{ $label }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
