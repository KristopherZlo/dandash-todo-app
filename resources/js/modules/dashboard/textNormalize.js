export function normalizeTodoComparableText(value) {
    const source = String(value ?? '').trim();
    if (source === '') {
        return '';
    }

    return source
        .toLowerCase()
        .replace(/\u0451/g, '\u0435')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
