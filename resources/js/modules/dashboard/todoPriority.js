export function normalizeTodoPriority(value, fallback = null) {
    const normalized = String(value ?? '').trim().toLowerCase();

    if (normalized === 'urgent' || normalized === 'today' || normalized === 'later') {
        return normalized;
    }

    return fallback;
}

export function inferTodoPriorityFromDueAt(isoValue) {
    const dueAt = isoValue ? new Date(isoValue) : null;
    if (!dueAt || Number.isNaN(dueAt.getTime())) {
        return 'later';
    }

    const now = new Date();
    const diffMs = dueAt.getTime() - now.getTime();
    const isToday = dueAt.toDateString() === now.toDateString();

    if (diffMs <= 2 * 60 * 60 * 1000) {
        return 'urgent';
    }

    if (isToday || diffMs <= 24 * 60 * 60 * 1000) {
        return 'today';
    }

    return 'later';
}

export function getTodoPriority(item) {
    return normalizeTodoPriority(item?.priority, inferTodoPriorityFromDueAt(item?.due_at));
}

export function todoPriorityLabel(item) {
    const priority = getTodoPriority(item);

    if (priority === 'urgent') {
        return '\u0421\u0440\u043e\u0447\u043d\u043e';
    }

    if (priority === 'today') {
        return '\u0421\u0435\u0433\u043e\u0434\u043d\u044f';
    }

    return '\u041f\u043e\u0442\u043e\u043c';
}

export function todoPriorityClass(item) {
    const priority = getTodoPriority(item);

    if (priority === 'urgent') {
        return 'border-[#ee5c81]/55 bg-[#ee5c81]/14 text-[#ee5c81]';
    }

    if (priority === 'today') {
        return 'border-[#d4b06e]/55 bg-[#d4b06e]/14 text-[#d4b06e]';
    }

    return 'border-[#a5d774]/50 bg-[#a5d774]/12 text-[#a5d774]';
}

export function nextTodoPriority(priority) {
    if (priority === 'urgent') {
        return 'today';
    }

    if (priority === 'today') {
        return 'later';
    }

    return 'urgent';
}
