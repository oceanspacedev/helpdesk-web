@props([
    'id',
    'label',
    'required' => false,
    'optional' => false,
    'error' => null,
    'hint' => null,
])

<div class="fi-fo-field">
    <div class="fi-fo-field-label-col">
        <div class="fi-fo-field-label-ctn">
            <label for="{{ $id }}" class="fi-fo-field-label">
                <span class="fi-fo-field-label-content">
                    {{ $label }}@if ($required)<sup class="fi-fo-field-label-required-mark">*</sup>@elseif ($optional)<span class="text-xs font-normal text-gray-400"> Opsional</span>@endif
                </span>
            </label>
        </div>
    </div>
    <div class="fi-fo-field-content-col">
        {{ $slot }}
        @if ($hint && blank($error))
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $hint }}</p>
        @endif
        @if (filled($error))
            <p data-validation-error class="fi-fo-field-wrp-error-message">{{ $error }}</p>
        @endif
    </div>
</div>
