const PRODUCT_UNIT_ALIASES = {
    // Pieces
    '\u0448\u0442': '\u0448\u0442',
    '\u0448\u0442\u0443\u043a\u0430': '\u0448\u0442',
    '\u0448\u0442\u0443\u043a\u0438': '\u0448\u0442',
    '\u0448\u0442\u0443\u043a': '\u0448\u0442',
    '\u0448\u0442\u0443\u0447\u043a\u0430': '\u0448\u0442',
    '\u0448\u0442\u0443\u0447\u043a\u0438': '\u0448\u0442',
    '\u0448\u0442\u0443\u0447\u0435\u043a': '\u0448\u0442',
    '\u0435\u0434': '\u0448\u0442',
    '\u0435\u0434\u0438\u043d\u0438\u0446\u0430': '\u0448\u0442',
    '\u0435\u0434\u0438\u043d\u0438\u0446\u044b': '\u0448\u0442',
    '\u0435\u0434\u0438\u043d\u0438\u0446': '\u0448\u0442',
    'pc': '\u0448\u0442',
    'pcs': '\u0448\u0442',
    'piece': '\u0448\u0442',
    'pieces': '\u0448\u0442',

    // Weight
    '\u043a\u0433': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e\u0433\u0440\u0430\u043c\u043c': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e\u0433\u0440\u0430\u043c\u043c\u0430': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e\u0433\u0440\u0430\u043c\u043c\u043e\u0432': '\u043a\u0433',
    'kg': '\u043a\u0433',
    'kilo': '\u043a\u0433',
    'kilos': '\u043a\u0433',

    '\u0433': '\u0433',
    '\u0433\u0440': '\u0433',
    '\u0433\u0440\u0430\u043c\u043c': '\u0433',
    '\u0433\u0440\u0430\u043c\u043c\u0430': '\u0433',
    '\u0433\u0440\u0430\u043c\u043c\u043e\u0432': '\u0433',
    'gram': '\u0433',
    'grams': '\u0433',

    // Volume
    '\u043b': '\u043b',
    '\u043b\u0438\u0442\u0440': '\u043b',
    '\u043b\u0438\u0442\u0440\u0430': '\u043b',
    '\u043b\u0438\u0442\u0440\u043e\u0432': '\u043b',
    'l': '\u043b',
    'liter': '\u043b',
    'liters': '\u043b',

    '\u043c\u043b': '\u043c\u043b',
    '\u043c\u0438\u043b\u043b\u0438\u043b\u0438\u0442\u0440': '\u043c\u043b',
    '\u043c\u0438\u043b\u043b\u0438\u043b\u0438\u0442\u0440\u0430': '\u043c\u043b',
    '\u043c\u0438\u043b\u043b\u0438\u043b\u0438\u0442\u0440\u043e\u0432': '\u043c\u043b',
    'ml': '\u043c\u043b',
    'milliliter': '\u043c\u043b',
    'milliliters': '\u043c\u043b',

    // Packs / packages
    '\u0443\u043f': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a\u043e\u0432\u043a\u0430': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a\u043e\u0432\u043a\u0438': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a\u043e\u0432\u043e\u043a': '\u0443\u043f',
    'pack': '\u0443\u043f',
    'packs': '\u0443\u043f',
    'package': '\u0443\u043f',
    'packages': '\u0443\u043f',
    'pkg': '\u0443\u043f',

    '\u043f\u0430\u0447': '\u043f\u0430\u0447',
    '\u043f\u0430\u0447\u043a\u0430': '\u043f\u0430\u0447',
    '\u043f\u0430\u0447\u043a\u0438': '\u043f\u0430\u0447',
    '\u043f\u0430\u0447\u0435\u043a': '\u043f\u0430\u0447',

    '\u043f\u0430\u043a': '\u043f\u0430\u043a',
    '\u043f\u0430\u043a\u0435\u0442': '\u043f\u0430\u043a',
    '\u043f\u0430\u043a\u0435\u0442\u0430': '\u043f\u0430\u043a',
    '\u043f\u0430\u043a\u0435\u0442\u043e\u0432': '\u043f\u0430\u043a',
    'packet': '\u043f\u0430\u043a',
    'packets': '\u043f\u0430\u043a',

    // Containers
    '\u0431\u0443\u0442': '\u0431\u0443\u0442',
    '\u0431\u0443\u0442\u044b\u043b\u043a\u0430': '\u0431\u0443\u0442',
    '\u0431\u0443\u0442\u044b\u043b\u043a\u0438': '\u0431\u0443\u0442',
    '\u0431\u0443\u0442\u044b\u043b\u043e\u043a': '\u0431\u0443\u0442',
    'bottle': '\u0431\u0443\u0442',
    'bottles': '\u0431\u0443\u0442',

    '\u0431\u0430\u043d': '\u0431\u0430\u043d',
    '\u0431\u0430\u043d\u043a\u0430': '\u0431\u0430\u043d',
    '\u0431\u0430\u043d\u043a\u0438': '\u0431\u0430\u043d',
    '\u0431\u0430\u043d\u043e\u043a': '\u0431\u0430\u043d',
    'jar': '\u0431\u0430\u043d',
    'jars': '\u0431\u0430\u043d',

    '\u043a\u043e\u0440': '\u043a\u043e\u0440',
    '\u043a\u043e\u0440\u043e\u0431\u043a\u0430': '\u043a\u043e\u0440',
    '\u043a\u043e\u0440\u043e\u0431\u043a\u0438': '\u043a\u043e\u0440',
    '\u043a\u043e\u0440\u043e\u0431\u043e\u043a': '\u043a\u043e\u0440',
    'box': '\u043a\u043e\u0440',
    'boxes': '\u043a\u043e\u0440',

    '\u0440\u0443\u043b': '\u0440\u0443\u043b',
    '\u0440\u0443\u043b\u043e\u043d': '\u0440\u0443\u043b',
    '\u0440\u0443\u043b\u043e\u043d\u0430': '\u0440\u0443\u043b',
    '\u0440\u0443\u043b\u043e\u043d\u043e\u0432': '\u0440\u0443\u043b',
    'roll': '\u0440\u0443\u043b',
    'rolls': '\u0440\u0443\u043b',

    // Dozen / portion
    '\u0434\u044e\u0436': '\u0434\u044e\u0436',
    '\u0434\u044e\u0436\u0438\u043d\u0430': '\u0434\u044e\u0436',
    '\u0434\u044e\u0436\u0438\u043d\u044b': '\u0434\u044e\u0436',
    '\u0434\u044e\u0436\u0438\u043d': '\u0434\u044e\u0436',
    'dozen': '\u0434\u044e\u0436',
    'dozens': '\u0434\u044e\u0436',
    'dz': '\u0434\u044e\u0436',

    '\u043f\u043e\u0440\u0446': '\u043f\u043e\u0440\u0446',
    '\u043f\u043e\u0440\u0446\u0438\u044f': '\u043f\u043e\u0440\u0446',
    '\u043f\u043e\u0440\u0446\u0438\u0438': '\u043f\u043e\u0440\u0446',
    '\u043f\u043e\u0440\u0446\u0438\u0439': '\u043f\u043e\u0440\u0446',
    'portion': '\u043f\u043e\u0440\u0446',
    'portions': '\u043f\u043e\u0440\u0446',
};

const PRODUCT_UNIT_PATTERN = Object.keys(PRODUCT_UNIT_ALIASES)
    .map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .sort((left, right) => right.length - left.length)
    .join('|');

export function normalizeQuantityInput(value) {
    const raw = String(value ?? '').trim().replace(',', '.');
    if (raw === '') {
        return null;
    }

    const parsed = Number(raw);
    if (!Number.isFinite(parsed) || parsed <= 0) {
        return null;
    }

    return Math.round(parsed * 100) / 100;
}

export function normalizeUnitInput(value) {
    const rawUnit = String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/\u0451/g, '\u0435')
        .replace(/[.,;:]+$/g, '');

    if (rawUnit === '') {
        return null;
    }

    const normalized = PRODUCT_UNIT_ALIASES[rawUnit];
    if (!normalized) {
        return null;
    }

    return normalized;
}

export function parseProductTextPayload(rawText) {
    const source = String(rawText ?? '').trim();
    if (source === '') {
        return {
            text: '',
            quantity: null,
            unit: null,
        };
    }

    const numberPattern = '(\\d+(?:[\\.,]\\d{1,2})?)';
    const unitPattern = `(${PRODUCT_UNIT_PATTERN})`;
    const separatorPattern = '[\\.,;:]?';
    const prefixMatcher = new RegExp(`^${numberPattern}\\s*${unitPattern}${separatorPattern}\\s+(.+)$`, 'iu');
    const suffixMatcher = new RegExp(`^(.+?)\\s+${numberPattern}\\s*${unitPattern}${separatorPattern}$`, 'iu');
    const prefixNumberOnlyMatcher = new RegExp(`^${numberPattern}${separatorPattern}\\s+(.+)$`, 'iu');
    const suffixNumberOnlyMatcher = new RegExp(`^(.+?)\\s+${numberPattern}${separatorPattern}$`, 'iu');

    let text = source;
    let quantity = null;
    let unit = null;

    const prefixMatch = source.match(prefixMatcher);
    if (prefixMatch) {
        quantity = normalizeQuantityInput(prefixMatch[1]);
        unit = normalizeUnitInput(prefixMatch[2]);
        text = String(prefixMatch[3] ?? '').trim();
    } else {
        const suffixMatch = source.match(suffixMatcher);
        if (suffixMatch) {
            text = String(suffixMatch[1] ?? '').trim();
            quantity = normalizeQuantityInput(suffixMatch[2]);
            unit = normalizeUnitInput(suffixMatch[3]);
        } else {
            const prefixNumberOnlyMatch = source.match(prefixNumberOnlyMatcher);
            if (prefixNumberOnlyMatch) {
                quantity = normalizeQuantityInput(prefixNumberOnlyMatch[1]);
                unit = 'шт';
                text = String(prefixNumberOnlyMatch[2] ?? '').trim();
            } else {
                const suffixNumberOnlyMatch = source.match(suffixNumberOnlyMatcher);
                if (suffixNumberOnlyMatch) {
                    text = String(suffixNumberOnlyMatch[1] ?? '').trim();
                    quantity = normalizeQuantityInput(suffixNumberOnlyMatch[2]);
                    unit = 'шт';
                }
            }
        }
    }

    if (quantity === null || unit === null || text === '') {
        return {
            text: source,
            quantity: null,
            unit: null,
        };
    }

    return {
        text,
        quantity,
        unit,
    };
}

export function normalizeProductComparableText(value) {
    const source = String(value ?? '').trim();
    if (source === '') {
        return '';
    }

    const parsed = parseProductTextPayload(source);
    const baseText = String(parsed.text ?? source).trim();

    return baseText
        .toLowerCase()
        .replace(/\u0451/g, '\u0435')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function formatQuantityValue(value) {
    const quantity = Number(value);
    if (!Number.isFinite(quantity)) {
        return '';
    }

    if (Number.isInteger(quantity)) {
        return String(quantity);
    }

    return String(Math.round(quantity * 100) / 100).replace(/\.?0+$/, '');
}

export function getProductDisplayParts(item) {
    const sourceText = String(item?.text ?? '').trim();
    const explicitQuantity = normalizeQuantityInput(item?.quantity);
    const explicitUnitRaw = String(item?.unit ?? '').trim();
    const explicitUnitNormalized = explicitQuantity !== null ? normalizeUnitInput(item?.unit) : null;
    const explicitUnit = explicitQuantity !== null
        ? (explicitUnitNormalized ?? (explicitUnitRaw === '' ? null : explicitUnitRaw.slice(0, 24)))
        : null;

    if (explicitQuantity !== null) {
        return {
            text: sourceText,
            quantity: explicitQuantity,
            unit: explicitUnit,
        };
    }

    const parsed = parseProductTextPayload(sourceText);
    if (parsed.quantity !== null && parsed.text !== '') {
        return parsed;
    }

    return {
        text: sourceText,
        quantity: null,
        unit: null,
    };
}

export function getProductDisplayText(item) {
    const parts = getProductDisplayParts(item);
    return parts.text;
}

export function formatProductMeasure(item) {
    if (item?.type !== 'product') {
        return '';
    }

    const parts = getProductDisplayParts(item);
    const quantity = parts.quantity !== null ? formatQuantityValue(parts.quantity) : '';
    const unit = parts.unit;

    if (quantity && unit) {
        return `${quantity} ${unit}`;
    }

    if (quantity) {
        return quantity;
    }

    if (unit) {
        return unit;
    }

    return '';
}

export function buildProductEditableText(item) {
    const parts = getProductDisplayParts(item);
    const text = parts.text;
    const quantity = parts.quantity !== null ? formatQuantityValue(parts.quantity) : '';
    const unit = parts.unit;

    if (!quantity) {
        return text;
    }

    if (unit) {
        return `${text} ${quantity} ${unit}`.trim();
    }

    return `${text} ${quantity}`.trim();
}
