<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import Icon from './Icon.vue';

const SEARCHABLE_AFTER = 5;

defineOptions({ inheritAttrs: false });

const props = defineProps({
    id: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    placeholder: { type: String, default: 'Pilih' },
    options: { type: Array, default: () => [] },
});

const model = defineModel({ default: '' });

const root = ref(null);
const searchEl = ref(null);
const listEl = ref(null);
const open = ref(false);
const query = ref('');
const activeIndex = ref(-1);

const searchable = computed(() => props.options.length > SEARCHABLE_AFTER);

const selected = computed(
    () => props.options.find((option) => String(option.id) === String(model.value)) ?? null,
);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) {
        return props.options;
    }

    return props.options.filter((option) => option.name.toLowerCase().includes(needle));
});

watch(
    () => props.options,
    (options) => {
        if (model.value !== '' && model.value != null && !options.some((option) => String(option.id) === String(model.value))) {
            model.value = '';
        }
    },
);

watch(filtered, (items) => {
    if (!open.value) {
        return;
    }
    activeIndex.value = items.length ? 0 : -1;
});

function same(option) {
    return selected.value && String(selected.value.id) === String(option.id);
}

function openPanel() {
    if (props.disabled || !searchable.value) {
        return;
    }

    open.value = true;
    query.value = '';
    activeIndex.value = Math.max(
        0,
        filtered.value.findIndex((option) => same(option)),
    );
    nextTick(() => {
        searchEl.value?.focus();
        scrollActive();
    });
}

function closePanel() {
    open.value = false;
    query.value = '';
    activeIndex.value = -1;
}

function togglePanel() {
    if (open.value) {
        closePanel();
        return;
    }
    openPanel();
}

function choose(option) {
    model.value = option.id;
    closePanel();
}

function scrollActive() {
    const item = listEl.value?.querySelector('[data-active="true"]');
    item?.scrollIntoView({ block: 'nearest' });
}

function onTriggerKeydown(event) {
    if (props.disabled) {
        return;
    }

    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
        event.preventDefault();
        openPanel();
    }
}

function onSearchKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        closePanel();
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!filtered.value.length) {
            return;
        }
        activeIndex.value = (activeIndex.value + 1) % filtered.value.length;
        nextTick(scrollActive);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (!filtered.value.length) {
            return;
        }
        activeIndex.value = (activeIndex.value - 1 + filtered.value.length) % filtered.value.length;
        nextTick(scrollActive);
        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        const option = filtered.value[activeIndex.value];
        if (option) {
            choose(option);
        }
    }
}

function onDocumentPointerDown(event) {
    if (!root.value?.contains(event.target)) {
        closePanel();
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown);
});

onUnmounted(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
});
</script>

<template>
    <div v-if="!searchable" class="fi-input-wrp fi-fo-select fi-fo-select-native" :class="{ 'fi-invalid': invalid, 'fi-disabled': disabled }">
        <div class="fi-input-wrp-content-ctn">
            <select
                :id="id"
                class="fi-select-input"
                :value="model ?? ''"
                :required="required"
                :disabled="disabled"
                v-bind="$attrs"
                @change="model = $event.target.value"
            >
                <option value="">{{ placeholder }}</option>
                <option v-for="option in options" :key="option.id" :value="option.id">
                    {{ option.name }}
                </option>
            </select>
        </div>
    </div>

    <div
        v-else
        ref="root"
        class="fi-select-input relative"
        :class="{ 'fi-disabled': disabled }"
    >
        <input
            :id="id"
            class="fi-sr-only"
            :value="model ?? ''"
            :required="required"
            tabindex="-1"
            autocomplete="off"
            @focus="openPanel"
        />

        <div class="fi-input-wrp" :class="{ 'fi-invalid': invalid, 'fi-disabled': disabled }">
            <div class="fi-input-wrp-content-ctn">
                <button
                    type="button"
                    class="fi-select-input-btn"
                    :disabled="disabled"
                    aria-haspopup="listbox"
                    :aria-expanded="open"
                    :aria-controls="`${id}-listbox`"
                    @click="togglePanel"
                    @keydown="onTriggerKeydown"
                >
                    <span class="fi-select-input-value-ctn">
                        <span v-if="selected" class="fi-select-input-value-label">{{ selected.name }}</span>
                        <span v-else class="fi-select-input-placeholder">{{ placeholder }}</span>
                    </span>
                </button>
            </div>
        </div>

        <div
            v-show="open"
            class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <div class="fi-select-input-search-ctn border-b border-gray-100 p-1 dark:border-white/5">
                <div class="fi-input-wrp">
                    <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">
                        <span class="fi-icon fi-size-sm text-gray-400">
                            <Icon name="magnifying-glass" />
                        </span>
                    </div>
                    <div class="fi-input-wrp-content-ctn">
                        <input
                            ref="searchEl"
                            v-model="query"
                            type="search"
                            class="fi-input"
                            placeholder="Cari..."
                            autocomplete="off"
                            @keydown="onSearchKeydown"
                        />
                    </div>
                </div>
            </div>

            <ul
                :id="`${id}-listbox`"
                ref="listEl"
                class="fi-select-input-options-ctn max-h-60 overflow-y-auto py-1"
                role="listbox"
            >
                <li v-if="!filtered.length" class="fi-select-input-message">Tidak ada hasil.</li>
                <li
                    v-for="(option, index) in filtered"
                    :key="option.id"
                    role="option"
                    :data-active="activeIndex === index"
                    :aria-selected="same(option)"
                >
                    <button
                        type="button"
                        class="fi-select-input-option w-full px-3 py-2 text-start text-sm text-gray-950 dark:text-white"
                        :class="[
                            activeIndex === index ? 'bg-gray-50 dark:bg-white/5' : '',
                            same(option) ? 'font-medium text-primary-600 dark:text-primary-400' : '',
                        ]"
                        @mouseenter="activeIndex = index"
                        @click="choose(option)"
                    >
                        {{ option.name }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
