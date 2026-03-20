import { ref } from 'vue';

export function useActionLocks() {
    const activeActionLocks = ref(new Set());

    function acquireActionLock(key) {
        const normalizedKey = String(key ?? '').trim();
        if (normalizedKey === '') {
            return false;
        }

        if (activeActionLocks.value.has(normalizedKey)) {
            return false;
        }

        const nextLocks = new Set(activeActionLocks.value);
        nextLocks.add(normalizedKey);
        activeActionLocks.value = nextLocks;
        return true;
    }

    function releaseActionLock(key) {
        const normalizedKey = String(key ?? '').trim();
        if (normalizedKey === '' || !activeActionLocks.value.has(normalizedKey)) {
            return;
        }

        const nextLocks = new Set(activeActionLocks.value);
        nextLocks.delete(normalizedKey);
        activeActionLocks.value = nextLocks;
    }

    function isActionLocked(key) {
        return activeActionLocks.value.has(String(key ?? '').trim());
    }

    function resetActionLocks() {
        if (activeActionLocks.value.size === 0) {
            return;
        }

        activeActionLocks.value = new Set();
    }

    return {
        acquireActionLock,
        releaseActionLock,
        isActionLocked,
        resetActionLocks,
    };
}
