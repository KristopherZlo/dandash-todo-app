function normalizePositiveInteger(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? Math.floor(parsed) : 0;
}

function normalizeString(value, fallback = '') {
    const normalized = String(value ?? '').trim();
    return normalized === '' ? fallback : normalized;
}

function normalizeIsoString(value) {
    const normalized = String(value ?? '').trim();
    return normalized === '' ? null : normalized;
}

function normalizeMember(entry) {
    const userId = normalizePositiveInteger(entry?.id ?? entry?.user_id);
    if (!userId) {
        return null;
    }

    return {
        id: userId,
        name: normalizeString(entry?.name, `User ${userId}`),
        email: normalizeString(entry?.email),
        tag: normalizeString(entry?.tag),
        role: normalizeString(entry?.role, 'editor'),
    };
}

export function normalizeListOption(entry) {
    const listId = normalizePositiveInteger(entry?.id ?? entry?.list_id ?? entry?.owner_id ?? entry?.link_id);
    if (!listId) {
        return null;
    }

    const members = (Array.isArray(entry?.members) ? entry.members : [])
        .map((member) => normalizeMember(member))
        .filter(Boolean);
    const memberCount = Math.max(
        normalizePositiveInteger(entry?.member_count),
        members.length || 0,
        1,
    );
    const name = normalizeString(entry?.name ?? entry?.label, `List ${listId}`);

    return {
        owner_id: listId,
        list_id: listId,
        link_id: listId,
        label: name,
        name,
        owner_user_id: normalizePositiveInteger(entry?.owner_user_id),
        role: normalizeString(entry?.role, 'editor'),
        member_count: memberCount,
        members,
        open_products_count: Math.max(0, Number(entry?.open_products_count) || 0),
        open_todos_count: Math.max(0, Number(entry?.open_todos_count) || 0),
        total_pending_count: Math.max(0, Number(entry?.total_pending_count) || 0),
        last_activity_at: normalizeIsoString(entry?.last_activity_at),
        updated_at: normalizeIsoString(entry?.updated_at),
        is_personal: memberCount <= 1,
        is_template: false,
    };
}

export function normalizeListOptions(entries) {
    return (Array.isArray(entries) ? entries : [])
        .map((entry) => normalizeListOption(entry))
        .filter(Boolean);
}

export function buildCompatibilityLinks(listOptions, currentUserId) {
    const sourceOptions = normalizeListOptions(listOptions);
    const selfUserId = normalizePositiveInteger(currentUserId);
    const byUserId = new Map();

    for (const option of sourceOptions) {
        for (const member of option.members) {
            if (!member || member.id === selfUserId) {
                continue;
            }

            if (!byUserId.has(member.id)) {
                byUserId.set(member.id, {
                    id: member.id,
                    other_user: {
                        id: member.id,
                        name: member.name,
                        email: member.email,
                        tag: member.tag,
                    },
                    related_list_ids: [option.list_id],
                });
                continue;
            }

            const existing = byUserId.get(member.id);
            existing.related_list_ids = Array.from(new Set([
                ...(Array.isArray(existing.related_list_ids) ? existing.related_list_ids : []),
                option.list_id,
            ]));
        }
    }

    return Array.from(byUserId.values());
}

export function normalizeTemplateOptions(entries) {
    return (Array.isArray(entries) ? entries : [])
        .map((entry) => {
            const templateId = normalizePositiveInteger(entry?.id);
            if (!templateId) {
                return null;
            }

            return {
                id: templateId,
                name: normalizeString(entry?.name, `Template ${templateId}`),
                product_count: Math.max(0, Number(entry?.product_count) || 0),
                todo_count: Math.max(0, Number(entry?.todo_count) || 0),
                updated_at: normalizeIsoString(entry?.updated_at),
            };
        })
        .filter(Boolean);
}

export function buildCrossListReminders(listOptions, selectedListId) {
    const activeListId = normalizePositiveInteger(selectedListId);

    return normalizeListOptions(listOptions)
        .filter((option) => option.list_id !== activeListId && option.total_pending_count > 0)
        .sort((left, right) => {
            const pendingDiff = right.total_pending_count - left.total_pending_count;
            if (pendingDiff !== 0) {
                return pendingDiff;
            }

            return String(right.last_activity_at ?? '').localeCompare(String(left.last_activity_at ?? ''));
        });
}

export function normalizeMoodPreferences(entry) {
    const source = entry && typeof entry === 'object' ? entry : {};

    return {
        fire_recent_emojis: Array.isArray(source.fire_recent_emojis)
            ? source.fire_recent_emojis.filter((emoji) => String(emoji ?? '').trim() !== '').slice(0, 3)
            : [],
        battery_recent_emojis: Array.isArray(source.battery_recent_emojis)
            ? source.battery_recent_emojis.filter((emoji) => String(emoji ?? '').trim() !== '').slice(0, 3)
            : [],
    };
}
