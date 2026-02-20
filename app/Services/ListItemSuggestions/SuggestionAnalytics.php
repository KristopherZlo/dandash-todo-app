<?php

namespace App\Services\ListItemSuggestions;

use App\Models\ListItemSuggestionState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SuggestionAnalytics
{
    private const MIN_OCCURRENCES = 2;

    private const DUE_RATIO_THRESHOLD = 0.9;

    private const UPCOMING_RATIO_THRESHOLD = 0.75;

    public function __construct(
        private readonly SuggestionTextNormalizer $textNormalizer
    ) {}

    /**
     * @param  array<int, array{text: string, timestamp: CarbonImmutable}>  $entries
     * @param  Collection<string, ListItemSuggestionState>  $statesByKey
     * @return array<int, array<string, mixed>>
     */
    public function buildSuggestions(
        array $entries,
        Collection $statesByKey,
        string $type,
        CarbonImmutable $now
    ): array {
        $clusters = [];

        foreach ($entries as $entry) {
            $text = trim((string) ($entry['text'] ?? ''));
            $timestamp = $entry['timestamp'] ?? null;
            if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                continue;
            }

            $normalized = $this->textNormalizer->normalizeText($text);

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
                ];

                $clusterIndex = array_key_last($clusters);
            }

            if (! in_array($normalized, $clusters[$clusterIndex]['normalized_samples'], true)) {
                $clusters[$clusterIndex]['normalized_samples'][] = $normalized;
            }

            $clusters[$clusterIndex]['variants'][$text] = ($clusters[$clusterIndex]['variants'][$text] ?? 0) + 1;
            $clusters[$clusterIndex]['latest_text'] = $text;
            $clusters[$clusterIndex]['timestamps'][] = $timestamp;
        }

        $suggestions = [];

        foreach ($clusters as $cluster) {
            $state = $this->resolveClusterStateForSamples($statesByKey, $cluster['normalized_samples']);
            $resetAt = $state?->reset_at?->toImmutable();

            $timestamps = $cluster['timestamps'];
            if ($resetAt !== null) {
                $timestamps = array_values(array_filter(
                    $timestamps,
                    static fn (CarbonImmutable $timestamp): bool => $timestamp->greaterThanOrEqualTo($resetAt),
                ));
            }

            usort(
                $timestamps,
                static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp()
            );
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
                'suggestion_key' => (string) ($cluster['normalized_samples'][0] ?? $this->textNormalizer->normalizeText($displayText)),
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

        usort($suggestions, static function (array $left, array $right): int {
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

        return $suggestions;
    }

    /**
     * @param  array<int, array{text: string, timestamp: CarbonImmutable}>  $entries
     * @param  Collection<string, ListItemSuggestionState>  $statesByKey
     * @return array<int, array<string, mixed>>
     */
    public function buildStats(array $entries, Collection $statesByKey, int $limit): array
    {
        $clusters = [];

        foreach ($entries as $entry) {
            $text = trim((string) ($entry['text'] ?? ''));
            $timestamp = $entry['timestamp'] ?? null;
            if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                continue;
            }

            $normalized = $this->textNormalizer->normalizeText($text);
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

            $clusters[$clusterIndex]['entries'][] = [
                'text' => $text,
                'timestamp' => $timestamp,
            ];
        }

        $stats = [];

        foreach ($clusters as $cluster) {
            $state = $this->resolveClusterStateForSamples($statesByKey, $cluster['normalized_samples']);
            $resetAt = $state?->reset_at?->toImmutable();

            $clusterEntries = array_values(array_filter(
                $cluster['entries'],
                static function (array $entry) use ($resetAt): bool {
                    $timestamp = $entry['timestamp'] ?? null;
                    if (! $timestamp instanceof CarbonImmutable) {
                        return false;
                    }

                    return $resetAt === null || $timestamp->greaterThanOrEqualTo($resetAt);
                }
            ));

            if ($clusterEntries === []) {
                continue;
            }

            usort(
                $clusterEntries,
                static fn (array $left, array $right): int => ($left['timestamp'])->getTimestamp() <=> ($right['timestamp'])->getTimestamp(),
            );

            $occurrences = count($clusterEntries);
            $intervals = [];

            for ($index = 1; $index < $occurrences; $index++) {
                /** @var CarbonImmutable $current */
                $current = $clusterEntries[$index]['timestamp'];
                /** @var CarbonImmutable $previous */
                $previous = $clusterEntries[$index - 1]['timestamp'];

                $delta = $current->diffInSeconds($previous, true);
                if ($delta > 0) {
                    $intervals[] = $delta;
                }
            }

            $averageIntervalSeconds = $intervals !== []
                ? (int) round(array_sum($intervals) / count($intervals))
                : null;

            $variantCounts = [];
            foreach ($clusterEntries as $entry) {
                $entryText = (string) ($entry['text'] ?? '');
                if ($entryText === '') {
                    continue;
                }

                $variantCounts[$entryText] = ($variantCounts[$entryText] ?? 0) + 1;
            }

            $sortedVariants = $this->sortVariants($variantCounts);
            $lastEntry = $clusterEntries[$occurrences - 1];
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
                'suggestion_key' => (string) ($cluster['normalized_samples'][0] ?? $this->textNormalizer->normalizeText($displayText)),
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

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return array<int, array<string, mixed>>
     */
    public function deduplicateSuggestions(array $suggestions): array
    {
        $deduplicated = [];
        $seen = [];

        foreach ($suggestions as $suggestion) {
            $suggestionKey = is_string($suggestion['suggestion_key'] ?? null)
                ? $this->textNormalizer->normalizeSuggestionKey((string) $suggestion['suggestion_key'])
                : '';

            if ($suggestionKey === '') {
                $suggestionKey = $this->textNormalizer->normalizeSuggestionKey((string) ($suggestion['suggested_text'] ?? ''));
            }

            if ($suggestionKey === '') {
                $suggestionKey = mb_strtolower(trim((string) ($suggestion['suggested_text'] ?? '')), 'UTF-8');
            }

            if ($suggestionKey !== '' && isset($seen[$suggestionKey])) {
                continue;
            }

            if ($suggestionKey !== '') {
                $seen[$suggestionKey] = true;
            }

            $deduplicated[] = $suggestion;
        }

        return $deduplicated;
    }

    /**
     * @param  Collection<string, ListItemSuggestionState>  $statesByKey
     * @param  array<int, string>  $samples
     */
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
     * @param  array<int, array<string, mixed>>  $clusters
     */
    private function resolveClusterIndex(array $clusters, string $normalized): ?int
    {
        foreach ($clusters as $index => $cluster) {
            foreach ($cluster['normalized_samples'] as $sample) {
                if ($sample === $normalized || $this->textNormalizer->isFuzzyMatch($sample, $normalized)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $intervals
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
     * @param  array<string, int>  $variants
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
}
