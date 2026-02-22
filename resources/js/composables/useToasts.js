import { ref } from 'vue';

const TOAST_AUTO_CLOSE_MS = 3200;
const TOAST_ERROR_CLOSE_MS = 4400;
const TOAST_SWIPE_DISMISS_THRESHOLD = 64;

function resolvePointerClientX(event) {
    if (!event) {
        return null;
    }

    if (typeof event.clientX === 'number') {
        return Number(event.clientX);
    }

    if ('touches' in event && event.touches?.length > 0) {
        return Number(event.touches[0].clientX);
    }

    if ('changedTouches' in event && event.changedTouches?.length > 0) {
        return Number(event.changedTouches[0].clientX);
    }

    return null;
}

export function useToasts() {
    const toasts = ref([]);
    let nextToastId = 1;

    function clearToastTimer(toast) {
        if (!toast || typeof window === 'undefined') {
            return;
        }

        if (toast.timerId) {
            clearTimeout(toast.timerId);
            toast.timerId = null;
        }
    }

    function removeToast(toastId) {
        const numericId = Number(toastId);
        const toast = toasts.value.find((entry) => Number(entry.id) === numericId);
        if (toast) {
            clearToastTimer(toast);
        }

        toasts.value = toasts.value.filter((entry) => Number(entry.id) !== numericId);
    }

    function scheduleToastAutoclose(toast, duration = TOAST_AUTO_CLOSE_MS) {
        if (!toast || typeof window === 'undefined') {
            return;
        }

        clearToastTimer(toast);
        const safeDuration = Math.max(900, Number(duration) || TOAST_AUTO_CLOSE_MS);

        toast.timerId = window.setTimeout(() => {
            removeToast(toast.id);
        }, safeDuration);
    }

    function pushToast(message, type = 'info', duration = TOAST_AUTO_CLOSE_MS) {
        const normalizedMessage = String(message ?? '').trim();
        if (!normalizedMessage) {
            return;
        }

        const toast = {
            id: nextToastId,
            type,
            message: normalizedMessage,
            deltaX: 0,
            startX: 0,
            dragging: false,
            pointerId: null,
            timerId: null,
            duration: Math.max(900, Number(duration) || TOAST_AUTO_CLOSE_MS),
        };
        nextToastId += 1;

        toasts.value = [...toasts.value, toast];
        scheduleToastAutoclose(toast, toast.duration);
    }

    function findToastById(toastId) {
        const numericId = Number(toastId);
        return toasts.value.find((entry) => Number(entry.id) === numericId) ?? null;
    }

    function onToastPointerDown(toastId, event) {
        const toast = findToastById(toastId);
        if (!toast) {
            return;
        }

        const clientX = resolvePointerClientX(event);
        if (clientX === null) {
            return;
        }

        toast.dragging = true;
        toast.startX = clientX;
        toast.deltaX = 0;
        toast.pointerId = typeof event?.pointerId === 'number' ? Number(event.pointerId) : null;
        clearToastTimer(toast);

        const pointerTarget = event?.currentTarget;
        if (
            pointerTarget
            && typeof pointerTarget.setPointerCapture === 'function'
            && toast.pointerId !== null
        ) {
            try {
                pointerTarget.setPointerCapture(toast.pointerId);
            } catch (error) {
                // Ignore capture errors on unsupported platforms.
            }
        }
    }

    function onToastPointerMove(toastId, event) {
        const toast = findToastById(toastId);
        if (!toast || !toast.dragging) {
            return;
        }

        if (
            toast.pointerId !== null
            && typeof event?.pointerId === 'number'
            && Number(event.pointerId) !== toast.pointerId
        ) {
            return;
        }

        const clientX = resolvePointerClientX(event);
        if (clientX === null) {
            return;
        }

        const delta = clientX - toast.startX;
        toast.deltaX = Math.max(-220, Math.min(220, delta));
    }

    function onToastPointerUp(toastId, event = null) {
        const toast = findToastById(toastId);
        if (!toast) {
            return;
        }

        const pointerTarget = event?.currentTarget;
        if (
            pointerTarget
            && typeof pointerTarget.releasePointerCapture === 'function'
            && toast.pointerId !== null
        ) {
            try {
                if (
                    typeof pointerTarget.hasPointerCapture !== 'function'
                    || pointerTarget.hasPointerCapture(toast.pointerId)
                ) {
                    pointerTarget.releasePointerCapture(toast.pointerId);
                }
            } catch (error) {
                // Ignore capture release errors on unsupported platforms.
            }
        }

        const dismiss = Math.abs(toast.deltaX) >= TOAST_SWIPE_DISMISS_THRESHOLD;
        toast.dragging = false;
        toast.pointerId = null;
        if (dismiss) {
            removeToast(toast.id);
            return;
        }

        toast.deltaX = 0;
        scheduleToastAutoclose(toast, toast.duration);
    }

    function onToastPointerCancel(toastId, event = null) {
        onToastPointerUp(toastId, event);
    }

    function resetMessages() {
        // Toast notifications do not require hard reset.
    }

    function showStatus(message) {
        pushToast(message, 'success', TOAST_AUTO_CLOSE_MS);
    }

    function showToast(message, type = 'info', duration = TOAST_AUTO_CLOSE_MS) {
        pushToast(message, type, duration);
    }

    function showError(error) {
        const fallback = '\u041f\u0440\u043e\u0438\u0437\u043e\u0448\u043b\u0430 \u043e\u0448\u0438\u0431\u043a\u0430. \u041f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0451 \u0440\u0430\u0437.';
        const responseErrors = error?.response?.data?.errors;
        const firstError = responseErrors ? Object.values(responseErrors)[0]?.[0] : null;
        pushToast(firstError || error?.response?.data?.message || fallback, 'error', TOAST_ERROR_CLOSE_MS);
    }

    function disposeToasts() {
        for (const toast of toasts.value) {
            clearToastTimer(toast);
        }

        toasts.value = [];
    }

    return {
        toasts,
        resetMessages,
        showToast,
        showStatus,
        showError,
        onToastPointerDown,
        onToastPointerMove,
        onToastPointerUp,
        onToastPointerCancel,
        disposeToasts,
    };
}
