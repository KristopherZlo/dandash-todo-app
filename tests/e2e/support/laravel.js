import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const supportDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(supportDir, '..', '..', '..');

function runArtisanTinker(code) {
    execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
        cwd: repoRoot,
        stdio: 'pipe',
    });
}

function escapeForSingleQuotedPhpString(value) {
    return String(value ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'");
}

function toPhpLiteral(value) {
    if (value === null) {
        return 'null';
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? String(value) : 'null';
    }

    if (typeof value === 'string') {
        return `'${escapeForSingleQuotedPhpString(value)}'`;
    }

    if (Array.isArray(value)) {
        return `[${value.map((entry) => toPhpLiteral(entry)).join(', ')}]`;
    }

    if (typeof value === 'object') {
        return `[${Object.entries(value)
            .map(([key, entryValue]) => `'${escapeForSingleQuotedPhpString(key)}' => ${toPhpLiteral(entryValue)}`)
            .join(', ')}]`;
    }

    throw new TypeError(`Unsupported PHP literal value: ${typeof value}`);
}

export function ensureRegistrationCode(code) {
    const normalizedCode = String(code ?? '').trim().toUpperCase();
    if (normalizedCode === '') {
        throw new Error('Registration code is required');
    }

    runArtisanTinker(
        `app('db')->table('registration_codes')->updateOrInsert(['code' => '${normalizedCode}'], ['used_by_user_id' => null, 'used_at' => null, 'created_at' => now(), 'updated_at' => now()]);`
    );

    return normalizedCode;
}

function toSqlTimestamp(date) {
    return new Date(date).toISOString().slice(0, 19).replace('T', ' ');
}

export function seedCompletedListItems(ownerId, names, type = 'product') {
    const normalizedOwnerId = Number(ownerId);
    if (!Number.isFinite(normalizedOwnerId) || normalizedOwnerId <= 0) {
        throw new Error('A valid owner id is required');
    }

    const rows = [];
    const baseDate = new Date();

    names.forEach((name, nameIndex) => {
        for (let repeatIndex = 0; repeatIndex < 2; repeatIndex += 1) {
            const entryDate = new Date(baseDate.getTime());
            entryDate.setUTCDate(entryDate.getUTCDate() - (8 - (repeatIndex * 4) + nameIndex));

            rows.push({
                owner_id: normalizedOwnerId,
                type,
                text: String(name),
                is_completed: true,
                completed_at: toSqlTimestamp(entryDate),
                created_by_id: normalizedOwnerId,
                updated_by_id: normalizedOwnerId,
                created_at: toSqlTimestamp(entryDate),
                updated_at: toSqlTimestamp(entryDate),
            });
        }
    });

    runArtisanTinker(
        `app('db')->table('list_items')->insert(${toPhpLiteral(rows)});`
    );
}
