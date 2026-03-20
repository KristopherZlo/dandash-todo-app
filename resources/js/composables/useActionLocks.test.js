import { describe, expect, it } from 'vitest';
import { useActionLocks } from './useActionLocks';

describe('useActionLocks', () => {
    it('acquires and releases named locks', () => {
        const locks = useActionLocks();

        expect(locks.acquireActionLock('invite:9')).toBe(true);
        expect(locks.acquireActionLock('invite:9')).toBe(false);
        expect(locks.isActionLocked('invite:9')).toBe(true);

        locks.releaseActionLock('invite:9');
        expect(locks.isActionLocked('invite:9')).toBe(false);
    });

    it('ignores empty keys and supports reset', () => {
        const locks = useActionLocks();

        expect(locks.acquireActionLock('')).toBe(false);
        expect(locks.activeActionLockKeys.value).toEqual([]);

        locks.acquireActionLock('set-mine:5');
        locks.acquireActionLock('send-invite:7');
        locks.resetActionLocks();

        expect(locks.activeActionLockKeys.value).toEqual([]);
        expect(locks.isActionLocked('set-mine:5')).toBe(false);
        expect(locks.isActionLocked('send-invite:7')).toBe(false);
    });
});
