import { ref } from 'vue';

export function useActionLocks() {
    const activeActionLockKeys = ref([]);
    const activeActionLocks = new Set();

    function setActionLockVisualState(key, locked) {
        const normalizedKey = String(key ?? '').trim();
        if (normalizedKey === '') {
            return;
        }

        if (locked) {
            if (!activeActionLockKeys.value.includes(normalizedKey)) {
                activeActionLockKeys.value = [...activeActionLockKeys.value, normalizedKey];
            }
            return;
        }

        activeActionLockKeys.value = activeActionLockKeys.value.filter((entry) => entry !== normalizedKey);
    }

    function acquireActionLock(key) {
        const normalizedKey = String(key ?? '').trim();
        if (normalizedKey === '') {
            return false;
        }

        if (activeActionLocks.has(normalizedKey)) {
            return false;
        }

        activeActionLocks.add(normalizedKey);
        setActionLockVisualState(normalizedKey, true);
        return true;
    }

    function releaseActionLock(key) {
        const normalizedKey = String(key ?? '').trim();
        activeActionLocks.delete(normalizedKey);
        setActionLockVisualState(normalizedKey, false);
    }

    function isActionLocked(key) {
        return activeActionLockKeys.value.includes(String(key ?? '').trim());
    }

    function resetActionLocks() {
        activeActionLocks.clear();
        activeActionLockKeys.value = [];
    }

    return {
        activeActionLockKeys,
        acquireActionLock,
        releaseActionLock,
        isActionLocked,
        resetActionLocks,
    };
}
