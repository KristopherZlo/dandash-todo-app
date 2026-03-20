import { ref } from 'vue';

export function useActionButtonSuccess() {
    const successState = ref({});
    const actionButtonSuccessTimers = new Map();

    function isActionButtonSuccess(key) {
        return Boolean(successState.value[String(key ?? '').trim()]);
    }

    function markActionButtonSuccess(key, resetAfterMs = 1800) {
        const normalizedKey = String(key ?? '').trim();
        if (normalizedKey === '') {
            return;
        }

        successState.value = {
            ...successState.value,
            [normalizedKey]: true,
        };

        const existingTimer = actionButtonSuccessTimers.get(normalizedKey);
        if (existingTimer) {
            globalThis.clearTimeout(existingTimer);
        }

        const timerId = globalThis.setTimeout(() => {
            actionButtonSuccessTimers.delete(normalizedKey);
            if (!successState.value[normalizedKey]) {
                return;
            }

            const nextState = { ...successState.value };
            delete nextState[normalizedKey];
            successState.value = nextState;
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
        isActionButtonSuccess,
        markActionButtonSuccess,
        disposeActionButtonSuccess,
    };
}
