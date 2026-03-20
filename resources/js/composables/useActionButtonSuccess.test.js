import { describe, expect, it, vi, afterEach } from 'vitest';
import { useActionButtonSuccess } from './useActionButtonSuccess';

describe('useActionButtonSuccess', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('marks success state and clears it after timeout', () => {
        vi.useFakeTimers();
        const success = useActionButtonSuccess();

        success.markActionButtonSuccess('invite:7', 900);
        expect(success.isActionButtonSuccess('invite:7')).toBe(true);

        vi.advanceTimersByTime(899);
        expect(success.isActionButtonSuccess('invite:7')).toBe(true);

        vi.advanceTimersByTime(1);
        expect(success.isActionButtonSuccess('invite:7')).toBe(false);
    });

    it('ignores empty keys and disposes timers safely', () => {
        vi.useFakeTimers();
        const success = useActionButtonSuccess();

        success.markActionButtonSuccess('', 900);
        expect(success.isActionButtonSuccess('')).toBe(false);

        success.markActionButtonSuccess('set-mine:5', 900);
        success.disposeActionButtonSuccess();

        vi.advanceTimersByTime(900);
        expect(success.isActionButtonSuccess('set-mine:5')).toBe(true);
    });
});
