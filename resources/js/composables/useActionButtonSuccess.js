import { ref } from 'vue';

export function useActionButtonSuccess() {
    const actionButtonSuccessState = ref({});
    const actionButtonSuccessTimers = new Map();

    function isActionButtonSuccess(key) {
        return Boolean(actionButtonSuccessState.value[String(key ?? '').trim()]);
    }

    function markActionButtonSuccess(key, resetAfterMs = 1800) {
        const normalizedKey = String(key ?? '').trim();
        if (normalizedKey === '') {
            return;
        }

        actionButtonSuccessState.value = {
            ...actionButtonSuccessState.value,
            [normalizedKey]: true,
        };

        const existingTimer = actionButtonSuccessTimers.get(normalizedKey);
        if (existingTimer) {
            globalThis.clearTimeout(existingTimer);
        }

        const timerId = globalThis.setTimeout(() => {
            actionButtonSuccessTimers.delete(normalizedKey);
            if (!actionButtonSuccessState.value[normalizedKey]) {
                return;
            }

            const nextState = { ...actionButtonSuccessState.value };
            delete nextState[normalizedKey];
            actionButtonSuccessState.value = nextState;
        }, Math.max(600, Number(resetAfterMs) || 1800));

        actionButtonSuccessTimers.set(normalizedKey, timerId);
    }

    function disposeActionButtonSuccess() {
        for (const timerId of actionButtonSuccessTimers.values()) {
            globalThis.clearTimeout(timerId);
        }
        actionButtonSuccessTimers.clear();
    }

    return {
        actionButtonSuccessState,
        isActionButtonSuccess,
        markActionButtonSuccess,
        disposeActionButtonSuccess,
    };
}
