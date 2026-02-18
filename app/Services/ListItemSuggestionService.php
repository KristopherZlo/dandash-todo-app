<?php

namespace App\Services;

use App\Models\ListItem;
use App\Models\ListItemSuggestionState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ListItemSuggestionService
{
    private const MIN_OCCURRENCES = 2;
    private const DUE_RATIO_THRESHOLD = 0.9;
    private const UPCOMING_RATIO_THRESHOLD = 0.75;
    private const DAY_SECONDS = 86400;
    private const CYRILLIC_TO_LATIN = [
        'а' => 'a',
        'б' => 'b',
        'в' => 'v',
        'г' => 'g',
        'д' => 'd',
        'е' => 'e',
        'ё' => 'e',
        'ж' => 'zh',
        'з' => 'z',
        'и' => 'i',
        'й' => 'y',
        'к' => 'k',
        'л' => 'l',
        'м' => 'm',
        'н' => 'n',
        'о' => 'o',
        'п' => 'p',
        'р' => 'r',
        'с' => 's',
        'т' => 't',
        'у' => 'u',
        'ф' => 'f',
        'х' => 'h',
        'ц' => 'ts',
        'ч' => 'ch',
        'ш' => 'sh',
        'щ' => 'shch',
        'ы' => 'y',
        'э' => 'e',
        'ю' => 'yu',
        'я' => 'ya',
        'ь' => '',
        'ъ' => '',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestForOwner(int $ownerId, string $type, int $limit = 8, ?int $listLinkId = null): array
    {
        $itemsQuery = ListItem::query()
            ->forOwner($ownerId)
            ->ofType($type)
            ->orderBy('created_at');

        if ($listLinkId) {
            $itemsQuery->where('list_link_id', $listLinkId);
        } else {
            $itemsQuery->whereNull('list_link_id');
        }

        $items = $itemsQuery->get(['id', 'text', 'created_at', 'is_completed']);

        $statesByKey = ListItemSuggestionState::query()
            ->forOwner($ownerId)
            ->ofType($type)
            ->get()
            ->keyBy('suggestion_key');

        $clusters = [];

        foreach ($items as $item) {
            $normalized = $this->normalizeText((string) $item->text);

            if ($normalized === '') {
                continue;
            }

            $clusterIndex = $this->resolveClusterIndex($clusters, $normalized);

            if ($clusterIndex === null) {
                $clusters[] = [
                    'normalized_samples' => [$normalized],
                    'variants' => [],
                    'latest_text' => '',
                    'timestamps' => [],
                    'has_active_incomplete' => false,
                ];

                $clusterIndex = array_key_last($clusters);
            }

            if (! in_array($normalized, $clusters[$clusterIndex]['normalized_samples'], true)) {
                $clusters[$clusterIndex]['normalized_samples'][] = $normalized;
            }

            $text = trim((string) $item->text);
            if ($text !== '') {
                $clusters[$clusterIndex]['variants'][$text] = ($clusters[$clusterIndex]['variants'][$text] ?? 0) + 1;
                $clusters[$clusterIndex]['latest_text'] = $text;
            }

            if ($item->created_at !== null) {
                $clusters[$clusterIndex]['timestamps'][] = $item->created_at->toImmutable();
            }

            if ($type === ListItem::TYPE_PRODUCT && ! (bool) $item->is_completed) {
                $clusters[$clusterIndex]['has_active_incomplete'] = true;
            }
        }

        $now = now()->toImmutable();
        $suggestions = [];

        foreach ($clusters as $cluster) {
            if ($type === ListItem::TYPE_PRODUCT && ($cluster['has_active_incomplete'] ?? false)) {
                continue;
            }

            $state = $this->resolveClusterStateForSamples($statesByKey, $cluster['normalized_samples']);
            $resetAt = $state?->reset_at?->toImmutable();

            $timestamps = $cluster['timestamps'];
            if ($resetAt !== null) {
                $timestamps = array_values(array_filter(
                    $timestamps,
                    static fn (CarbonImmutable $timestamp): bool => $timestamp->greaterThanOrEqualTo($resetAt),
                ));
            }

            usort($timestamps, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp());
            $occurrences = count($timestamps);

            if ($occurrences < self::MIN_OCCURRENCES) {
                continue;
            }

            $intervals = [];
            for ($index = 1; $index < $occurrences; $index++) {
                $delta = $timestamps[$index]->diffInSeconds($timestamps[$index - 1], true);
                if ($delta > 0) {
                    $intervals[] = $delta;
                }
            }

            if (count($intervals) === 0) {
                continue;
            }

            $averageIntervalSeconds = (int) round(array_sum($intervals) / count($intervals));
            $lastAddedAt = $timestamps[$occurrences - 1];
            $elapsedSeconds = max(0, $now->getTimestamp() - $lastAddedAt->getTimestamp());
            $dueRatio = $averageIntervalSeconds > 0
                ? $elapsedSeconds / $averageIntervalSeconds
                : 0.0;

            $isDue = $dueRatio >= self::DUE_RATIO_THRESHOLD;
            $isUpcoming = ! $isDue && $dueRatio >= self::UPCOMING_RATIO_THRESHOLD;

            if (! $isDue && ! $isUpcoming) {
                continue;
            }

            $nextExpectedAt = $lastAddedAt->addSeconds($averageIntervalSeconds);
            $secondsUntilExpected = max(0, $nextExpectedAt->getTimestamp() - $now->getTimestamp());
            $confidence = $this->estimateConfidence($intervals, $occurrences);
            $sortedVariants = $this->sortVariants($cluster['variants']);
            $displayText = $cluster['latest_text'] !== ''
                ? $cluster['latest_text']
                : (array_key_first($sortedVariants) ?? '');

            if ($displayText === '') {
                continue;
            }

            $suggestions[] = [
                'suggested_text' => $displayText,
                'suggestion_key' => (string) ($cluster['normalized_samples'][0] ?? $this->normalizeText($displayText)),
                'type' => $type,
                'occurrences' => $occurrences,
                'average_interval_seconds' => $averageIntervalSeconds,
                'last_added_at' => $lastAddedAt->toISOString(),
                'next_expected_at' => $nextExpectedAt->toISOString(),
                'seconds_until_expected' => $secondsUntilExpected,
                'is_due' => $isDue,
                'due_ratio' => round($dueRatio, 2),
                'confidence' => $confidence,
                'variants' => array_slice(array_keys($sortedVariants), 0, 4),
            ];
        }

        usort($suggestions, function (array $left, array $right): int {
            $leftDue = $left['is_due'] ? 1 : 0;
            $rightDue = $right['is_due'] ? 1 : 0;

            if ($leftDue !== $rightDue) {
                return $rightDue <=> $leftDue;
            }

            if ($left['due_ratio'] !== $right['due_ratio']) {
                return $right['due_ratio'] <=> $left['due_ratio'];
            }

            if ($left['confidence'] !== $right['confidence']) {
                return $right['confidence'] <=> $left['confidence'];
            }

            return $right['occurrences'] <=> $left['occurrences'];
        });

        $candidates = $suggestions !== []
            ? $suggestions
            : ($listLinkId ? [] : $this->mockSuggestionsForOwner($ownerId, $type, $limit, $now));

        $filtered = $this->filterSuppressedSuggestions($ownerId, $type, $candidates, $now);

        return array_slice($filtered, 0, max(1, $limit));
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function productStatsForOwner(int $ownerId, int $limit = 50, ?int $listLinkId = null): array
    {
        $limit = max(1, min(200, $limit));

        $itemsQuery = ListItem::query()
            ->forOwner($ownerId)
            ->ofType(ListItem::TYPE_PRODUCT)
            ->where('is_completed', true)
            ->orderBy('completed_at')
            ->orderBy('created_at');

        if ($listLinkId) {
            $itemsQuery->where('list_link_id', $listLinkId);
        } else {
            $itemsQuery->whereNull('list_link_id');
        }

        $items = $itemsQuery->get(['id', 'text', 'created_at', 'completed_at']);

        $statesByKey = ListItemSuggestionState::query()
            ->forOwner($ownerId)
            ->ofType(ListItem::TYPE_PRODUCT)
            ->get()
            ->keyBy('suggestion_key');

        $clusters = [];

        foreach ($items as $item) {
            $normalized = $this->normalizeText((string) $item->text);
            if ($normalized === '') {
                continue;
            }

            $clusterIndex = $this->resolveClusterIndex($clusters, $normalized);

            if ($clusterIndex === null) {
                $clusters[] = [
                    'normalized_samples' => [$normalized],
                    'entries' => [],
                ];

                $clusterIndex = array_key_last($clusters);
            }

            if (! in_array($normalized, $clusters[$clusterIndex]['normalized_samples'], true)) {
                $clusters[$clusterIndex]['normalized_samples'][] = $normalized;
            }

            $timestamp = $item->completed_at?->toImmutable() ?? $item->created_at?->toImmutable();
            if ($timestamp === null) {
                continue;
            }

            $text = trim((string) $item->text);
            if ($text === '') {
                continue;
            }

            $clusters[$clusterIndex]['entries'][] = [
                'text' => $text,
                'timestamp' => $timestamp,
            ];
        }

        $stats = [];

        foreach ($clusters as $cluster) {
            $state = $this->resolveClusterStateForSamples($statesByKey, $cluster['normalized_samples']);
            $resetAt = $state?->reset_at?->toImmutable();

            $entries = array_values(array_filter(
                $cluster['entries'],
                static function (array $entry) use ($resetAt): bool {
                    $timestamp = $entry['timestamp'] ?? null;
                    if (! $timestamp instanceof CarbonImmutable) {
                        return false;
                    }

                    return $resetAt === null || $timestamp->greaterThanOrEqualTo($resetAt);
                }
            ));

            if ($entries === []) {
                continue;
            }

            usort(
                $entries,
                static fn (array $left, array $right): int => ($left['timestamp'])->getTimestamp() <=> ($right['timestamp'])->getTimestamp(),
            );

            $occurrences = count($entries);
            $intervals = [];

            for ($index = 1; $index < $occurrences; $index++) {
                /** @var CarbonImmutable $current */
                $current = $entries[$index]['timestamp'];
                /** @var CarbonImmutable $previous */
                $previous = $entries[$index - 1]['timestamp'];

                $delta = $current->diffInSeconds($previous, true);
                if ($delta > 0) {
                    $intervals[] = $delta;
                }
            }

            $averageIntervalSeconds = $intervals !== []
                ? (int) round(array_sum($intervals) / count($intervals))
                : null;

            $variantCounts = [];
            foreach ($entries as $entry) {
                $entryText = (string) ($entry['text'] ?? '');
                if ($entryText === '') {
                    continue;
                }

                $variantCounts[$entryText] = ($variantCounts[$entryText] ?? 0) + 1;
            }

            $sortedVariants = $this->sortVariants($variantCounts);
            $lastEntry = $entries[$occurrences - 1];
            /** @var CarbonImmutable $lastTimestamp */
            $lastTimestamp = $lastEntry['timestamp'];
            $displayText = (string) ($lastEntry['text'] ?? '');

            if ($displayText === '') {
                $displayText = (string) (array_key_first($sortedVariants) ?? '');
            }

            if ($displayText === '') {
                continue;
            }

            $stats[] = [
                'suggestion_key' => (string) ($cluster['normalized_samples'][0] ?? $this->normalizeText($displayText)),
                'text' => $displayText,
                'occurrences' => $occurrences,
                'average_interval_seconds' => $averageIntervalSeconds,
                'last_completed_at' => $lastTimestamp->toISOString(),
                'variants' => array_slice(array_keys($sortedVariants), 0, 4),
                'dismissed_count' => (int) ($state?->dismissed_count ?? 0),
                'hidden_until' => $state?->hidden_until?->toISOString(),
                'retired_at' => $state?->retired_at?->toISOString(),
                'reset_at' => $state?->reset_at?->toISOString(),
            ];
        }

        usort($stats, static function (array $left, array $right): int {
            if (($left['occurrences'] ?? 0) !== ($right['occurrences'] ?? 0)) {
                return ($right['occurrences'] ?? 0) <=> ($left['occurrences'] ?? 0);
            }

            return strcmp(
                (string) ($right['last_completed_at'] ?? ''),
                (string) ($left['last_completed_at'] ?? ''),
            );
        });

        return array_slice($stats, 0, $limit);
    }

    public function resetSuggestionData(int $ownerId, string $type, string $suggestionKey): void
    {
        $normalizedKey = $this->normalizeSuggestionKey($suggestionKey);

        if ($normalizedKey === '') {
            return;
        }

        $state = ListItemSuggestionState::query()->firstOrNew([
            'owner_id' => $ownerId,
            'type' => $type,
            'suggestion_key' => $normalizedKey,
        ]);

        $state->dismissed_count = 0;
        $state->hidden_until = null;
        $state->retired_at = null;
        $state->reset_at = now();
        $state->save();
    }

    public function normalizeSuggestionKey(string $value): string
    {
        return $this->normalizeText($value);
    }

    public function dismissSuggestion(
        int $ownerId,
        string $type,
        string $suggestionKey,
        int $averageIntervalSeconds
    ): void {
        $normalizedKey = $this->normalizeSuggestionKey($suggestionKey);

        if ($normalizedKey === '') {
            return;
        }

        $state = ListItemSuggestionState::query()->firstOrNew([
            'owner_id' => $ownerId,
            'type' => $type,
            'suggestion_key' => $normalizedKey,
        ]);

        $nextDismissCount = max(0, (int) $state->dismissed_count) + 1;
        $now = now()->toImmutable();

        if ($nextDismissCount >= 4) {
            $state->dismissed_count = 0;
            $state->hidden_until = null;
            $state->retired_at = $now;
            $state->save();

            return;
        }

        $averageIntervalSeconds = max(0, $averageIntervalSeconds);
        $hideSeconds = match ($nextDismissCount) {
            1 => self::DAY_SECONDS,
            2 => max(self::DAY_SECONDS, (int) floor($averageIntervalSeconds / 2)),
            3 => max(1, $averageIntervalSeconds),
            default => self::DAY_SECONDS,
        };

        $state->dismissed_count = $nextDismissCount;
        $state->hidden_until = $now->addSeconds($hideSeconds);
        $state->retired_at = null;
        $state->save();
    }


    private function resolveClusterStateForSamples(Collection $statesByKey, array $samples): ?ListItemSuggestionState
    {
        $resolvedState = null;

        foreach ($samples as $sample) {
            if (! is_string($sample) || $sample === '') {
                continue;
            }

            $candidate = $statesByKey->get($sample);
            if (! $candidate instanceof ListItemSuggestionState) {
                continue;
            }

            if (! $resolvedState instanceof ListItemSuggestionState) {
                $resolvedState = $candidate;
                continue;
            }

            $resolvedResetAt = $resolvedState->reset_at?->getTimestamp() ?? 0;
            $candidateResetAt = $candidate->reset_at?->getTimestamp() ?? 0;

            if ($candidateResetAt > $resolvedResetAt) {
                $resolvedState = $candidate;
            }
        }

        return $resolvedState;
    }

    /**
     * @param array<int, array<string, mixed>> $clusters
     */
    private function resolveClusterIndex(array $clusters, string $normalized): ?int
    {
        foreach ($clusters as $index => $cluster) {
            foreach ($cluster['normalized_samples'] as $sample) {
                if ($sample === $normalized || $this->isFuzzyMatch($sample, $normalized)) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        $normalized = Str::of($this->transliterateToLatin($value))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            $normalized = Str::of($value)
                ->lower()
                ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
                ->squish()
                ->value();

            if ($normalized === '') {
                return '';
            }
        }

        $tokens = array_values(array_filter(explode(' ', $normalized), static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            return '';
        }

        $tokens = array_map([$this, 'normalizeToken'], $tokens);
        $tokens = array_values(array_unique(array_filter($tokens, static fn (string $token): bool => $token !== '')));

        sort($tokens);

        return implode(' ', $tokens);
    }

    private function normalizeToken(string $token): string
    {
        if (! preg_match('/^[a-z0-9]+$/', $token)) {
            return $token;
        }

        $length = strlen($token);

        if ($length <= 3) {
            return $token;
        }

        if ($length > 4 && str_ends_with($token, 'ies')) {
            return substr($token, 0, -3).'y';
        }

        if ($length > 4 && str_ends_with($token, 'es')) {
            return substr($token, 0, -2);
        }

        if ($length > 4 && str_ends_with($token, 's')) {
            return substr($token, 0, -1);
        }

        return $token;
    }

    private function transliterateToLatin(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');

        return strtr($lower, self::CYRILLIC_TO_LATIN);
    }

    private function isFuzzyMatch(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        $maxLength = max(strlen($first), strlen($second));
        $minLength = min(strlen($first), strlen($second));

        if ($maxLength === 0) {
            return false;
        }

        if ($maxLength >= 5 && (str_contains($first, $second) || str_contains($second, $first))) {
            if ($minLength / $maxLength >= 0.75) {
                return true;
            }
        }

        $distance = levenshtein($first, $second);
        $distanceThreshold = max(1, (int) floor($maxLength * 0.22));
        if ($distance <= $distanceThreshold) {
            return true;
        }

        similar_text($first, $second, $percent);
        if ($percent >= 78.0) {
            return true;
        }

        $firstTokens = array_values(array_filter(explode(' ', $first), static fn (string $token): bool => $token !== ''));
        $secondTokens = array_values(array_filter(explode(' ', $second), static fn (string $token): bool => $token !== ''));

        if ($firstTokens === [] || $secondTokens === []) {
            return false;
        }

        $intersection = count(array_intersect($firstTokens, $secondTokens));
        $union = count(array_unique([...$firstTokens, ...$secondTokens]));

        return $union > 0 && ($intersection / $union) >= 0.6;
    }

    /**
     * @param array<int, int> $intervals
     */
    private function estimateConfidence(array $intervals, int $occurrences): float
    {
        $average = array_sum($intervals) / count($intervals);
        if ($average <= 0) {
            return 0.0;
        }

        $variance = 0.0;
        foreach ($intervals as $interval) {
            $variance += ($interval - $average) ** 2;
        }

        $variance /= count($intervals);
        $standardDeviation = sqrt($variance);
        $consistency = max(0.0, min(1.0, 1 - ($standardDeviation / $average)));
        $frequency = max(0.0, min(1.0, $occurrences / 8));

        return round(($consistency * 0.55) + ($frequency * 0.45), 2);
    }

    /**
     * @param array<string, int> $variants
     * @return array<string, int>
     */
    private function sortVariants(array $variants): array
    {
        uksort($variants, static function (string $left, string $right) use ($variants): int {
            $leftCount = $variants[$left];
            $rightCount = $variants[$right];

            if ($leftCount !== $rightCount) {
                return $rightCount <=> $leftCount;
            }

            return strcmp($left, $right);
        });

        return $variants;
    }

    /**
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function filterSuppressedSuggestions(
        int $ownerId,
        string $type,
        array $suggestions,
        CarbonImmutable $now
    ): array {
        $keys = collect($suggestions)
            ->pluck('suggestion_key')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($keys === []) {
            return $suggestions;
        }

        $states = ListItemSuggestionState::query()
            ->forOwner($ownerId)
            ->ofType($type)
            ->whereIn('suggestion_key', $keys)
            ->get()
            ->keyBy('suggestion_key');

        $filtered = [];

        foreach ($suggestions as $suggestion) {
            $suggestionKey = is_string($suggestion['suggestion_key'] ?? null)
                ? $suggestion['suggestion_key']
                : '';

            if ($suggestionKey === '') {
                $filtered[] = $suggestion;
                continue;
            }

            $state = $states->get($suggestionKey);

            if (! $state) {
                $filtered[] = $suggestion;
                continue;
            }

            if ($state->retired_at !== null) {
                continue;
            }

            if ($state->hidden_until !== null && $state->hidden_until->greaterThan($now)) {
                continue;
            }

            $filtered[] = $suggestion;
        }

        return $filtered;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mockSuggestionsForOwner(int $ownerId, string $type, int $limit, CarbonImmutable $now): array
    {
        $productPresets = [
            ['Творог', 'Кефир', 'Бананы', 'Яйца', 'Хлеб'],
            ['Овсянка', 'Греческий йогурт', 'Сыр', 'Помидоры', 'Кофе'],
            ['Куриное филе', 'Рис', 'Огурцы', 'Апельсины', 'Орехи'],
        ];

        $todoPresets = [
            ['Позвонить маме', 'Оплатить интернет', 'Полить цветы', 'Сделать зарядку'],
            ['Заказать воду', 'Проверить документы', 'Записаться к врачу', 'Вынести мусор'],
            ['Проверить бюджет', 'Купить корм коту', 'Сделать уборку', 'Подготовить стирку'],
        ];

        $presetIndex = max(0, $ownerId % 3);
        $pool = $type === ListItem::TYPE_PRODUCT
            ? $productPresets[$presetIndex]
            : $todoPresets[$presetIndex];

        $suggestions = [];

        foreach (array_slice($pool, 0, max(1, $limit)) as $index => $text) {
            $nextExpectedAt = $now->addMinutes((($index + 1) * 45));
            $secondsUntilExpected = max(0, $nextExpectedAt->getTimestamp() - $now->getTimestamp());
            $isDue = $index === 0;

            $suggestions[] = [
                'suggested_text' => $text,
                'suggestion_key' => $this->normalizeSuggestionKey($text),
                'type' => $type,
                'occurrences' => 3 + $index,
                'average_interval_seconds' => 86400 + ($index * 7200),
                'last_added_at' => $now->subDays($index + 1)->toISOString(),
                'next_expected_at' => $nextExpectedAt->toISOString(),
                'seconds_until_expected' => $isDue ? 0 : $secondsUntilExpected,
                'is_due' => $isDue,
                'due_ratio' => $isDue ? 1.0 : 0.72,
                'confidence' => 0.77 - ($index * 0.05),
                'variants' => [$text],
                'is_mock' => true,
            ];
        }

        return $suggestions;
    }
}
