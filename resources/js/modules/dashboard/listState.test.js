import { describe, expect, it } from 'vitest';
import {
    buildCompatibilityLinks,
    buildCrossListReminders,
    normalizeListOptions,
    normalizeMoodPreferences,
} from './listState';

describe('listState', () => {
    it('normalizes list options with members and pending counts', () => {
        const [list] = normalizeListOptions([{
            id: '14',
            name: 'Trip',
            owner_user_id: '7',
            member_count: 2,
            open_products_count: '3',
            total_pending_count: '5',
            members: [
                { user_id: 7, name: 'Owner', role: 'owner' },
                { user_id: 9, name: 'Editor', role: 'editor' },
            ],
        }]);

        expect(list).toMatchObject({
            list_id: 14,
            owner_id: 14,
            name: 'Trip',
            owner_user_id: 7,
            member_count: 2,
            total_pending_count: 5,
            is_personal: false,
        });
        expect(list.members).toHaveLength(2);
    });

    it('builds compatibility links and merges related list ids by user', () => {
        const links = buildCompatibilityLinks([
            {
                id: 11,
                members: [
                    { user_id: 1, name: 'Me' },
                    { user_id: 2, name: 'Alex', tag: 'alex' },
                ],
            },
            {
                id: 12,
                members: [
                    { user_id: 1, name: 'Me' },
                    { user_id: 2, name: 'Alex', tag: 'alex' },
                    { user_id: 3, name: 'Sam', tag: 'sam' },
                ],
            },
        ], 1);

        expect(links).toHaveLength(2);
        expect(links[0]).toMatchObject({
            id: 2,
            related_list_ids: [11, 12],
        });
        expect(links[1]).toMatchObject({
            id: 3,
            related_list_ids: [12],
        });
    });

    it('builds cross-list reminders sorted by pending count and recency', () => {
        const reminders = buildCrossListReminders([
            { id: 7, name: 'Current', total_pending_count: 4, last_activity_at: '2026-03-28T10:00:00Z' },
            { id: 8, name: 'Trip', total_pending_count: 6, last_activity_at: '2026-03-28T09:00:00Z' },
            { id: 9, name: 'Work', total_pending_count: 6, last_activity_at: '2026-03-28T11:00:00Z' },
            { id: 10, name: 'Empty', total_pending_count: 0, last_activity_at: '2026-03-28T12:00:00Z' },
        ], 7);

        expect(reminders.map((entry) => entry.list_id)).toEqual([9, 8]);
    });

    it('normalizes mood preferences to recent unique slots payload', () => {
        expect(normalizeMoodPreferences({
            fire_recent_emojis: ['🔥', '', '🚀', '🙂'],
            battery_recent_emojis: ['🔋', '⚡', '🌧️', '🪫'],
        })).toEqual({
            fire_recent_emojis: ['🔥', '🚀', '🙂'],
            battery_recent_emojis: ['🔋', '⚡', '🌧️'],
        });
    });
});
