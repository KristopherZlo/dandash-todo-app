export function formatIntervalSeconds(rawSeconds) {
    const seconds = Math.max(0, Number(rawSeconds) || 0);

    if (seconds >= 86400) {
        return `${Math.round((seconds / 86400) * 10) / 10} \u0434\u043d.`;
    }

    if (seconds >= 3600) {
        return `${Math.round((seconds / 3600) * 10) / 10} \u0447`;
    }

    return `${Math.max(1, Math.round(seconds / 60))} \u043c\u0438\u043d`;
}

export function suggestionStatusText(suggestion, type) {
    if (suggestion.is_due || Number(suggestion.seconds_until_expected) <= 0) {
        return type === 'product'
            ? '\u041f\u043e\u0440\u0430 \u043a\u0443\u043f\u0438\u0442\u044c \u0441\u043d\u043e\u0432\u0430'
            : '\u041f\u043e\u0440\u0430 \u0437\u0430\u043f\u043b\u0430\u043d\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0441\u043d\u043e\u0432\u0430';
    }

    return `\u0427\u0435\u0440\u0435\u0437 ${formatIntervalSeconds(suggestion.seconds_until_expected)}`;
}
