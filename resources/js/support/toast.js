import { reactive } from 'vue';

const MAX_TOASTS = 3;
const DEFAULT_DURATION = 5000;

const toasts = reactive([]);
const timers = new Map();
let sequence = 0;

function clearTimer(id) {
    const timer = timers.get(id);
    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }
}

function scheduleDismiss(item, ms) {
    clearTimer(item.id);
    if (!ms || ms <= 0) {
        return;
    }

    item.startedAt = Date.now();
    item.remainingMs = ms;
    const timer = setTimeout(() => {
        dismiss(item.id);
    }, ms);
    timers.set(item.id, timer);
}

/**
 * Tampilkan notifikasi toast mengambang dengan dukungan deduplikasi & pause.
 */
function show(options) {
    const normalized = typeof options === 'string' ? { title: options } : (options ?? {});
    const color = normalized.color ?? 'info';
    const duration = normalized.duration ?? DEFAULT_DURATION;
    const dedupeKey = normalized.dedupeKey ?? null;

    if (dedupeKey) {
        const existingIndex = toasts.findIndex((item) => item.dedupeKey === dedupeKey);
        if (existingIndex !== -1) {
            clearTimer(toasts[existingIndex].id);
            toasts.splice(existingIndex, 1);
        }
    }

    while (toasts.length >= MAX_TOASTS) {
        const oldest = toasts.shift();
        if (oldest) {
            clearTimer(oldest.id);
        }
    }

    const item = reactive({
        id: ++sequence,
        dedupeKey,
        color,
        title: normalized.title ?? '',
        description: normalized.description ?? '',
        actionLabel: normalized.actionLabel ?? null,
        actionUrl: normalized.actionUrl ?? null,
        duration,
        remainingMs: duration,
        startedAt: Date.now(),
        paused: false,
    });

    toasts.push(item);

    if (duration > 0) {
        scheduleDismiss(item, duration);
    }

    return item.id;
}

function success(options) {
    const payload = typeof options === 'string' ? { title: options } : (options ?? {});
    return show({ ...payload, color: 'success' });
}

function info(options) {
    const payload = typeof options === 'string' ? { title: options } : (options ?? {});
    return show({ ...payload, color: 'info' });
}

function danger(options) {
    const payload = typeof options === 'string' ? { title: options } : (options ?? {});
    return show({ ...payload, color: 'danger' });
}

function dismiss(id) {
    clearTimer(id);
    const index = toasts.findIndex((item) => item.id === id);
    if (index !== -1) {
        toasts.splice(index, 1);
    }
}

function pause(id) {
    const item = toasts.find((entry) => entry.id === id);
    if (!item || item.paused || item.duration <= 0) {
        return;
    }

    clearTimer(id);
    const elapsed = Math.max(0, Date.now() - item.startedAt);
    item.remainingMs = Math.max(400, item.remainingMs - elapsed);
    item.paused = true;
}

function resume(id) {
    const item = toasts.find((entry) => entry.id === id);
    if (!item || !item.paused || item.duration <= 0) {
        return;
    }

    item.paused = false;
    scheduleDismiss(item, Math.max(400, item.remainingMs));
}

function clear() {
    for (const item of toasts) {
        clearTimer(item.id);
    }
    toasts.splice(0, toasts.length);
}

export function useToast() {
    return {
        toasts,
        show,
        success,
        info,
        danger,
        dismiss,
        pause,
        resume,
        clear,
    };
}
