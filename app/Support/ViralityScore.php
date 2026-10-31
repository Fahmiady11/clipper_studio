<?php

namespace App\Support;

/**
 * Normalises the virality data returned by the clip-selection model so the
 * database only ever stores a bounded score and canonical reasons.
 */
final class ViralityScore
{
    /** Stable order for the UI and JSON column. */
    private const REASONS = ['hook', 'flow', 'value'];

    private const REASON_SYNONYMS = [
        'hook' => 'hook',
        'flow' => 'flow',
        'value' => 'value',
        'aliran' => 'flow',
        'pacing' => 'flow',
        'nilai' => 'value',
    ];

    public static function score(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(10, (int) round((float) $value)));
    }

    /**
     * @return list<string> hook / flow / value, or null when the model omitted it
     */
    public static function reasons(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        // Tolerate a JSON-encoded value or comma-separated free text, while the
        // normal prompt path returns a JSON array.
        $items = is_array($value)
            ? $value
            : (is_string($value) ? preg_split('/[,;|]/', $value) ?: [] : []);

        $matched = [];
        foreach ($items as $key => $item) {
            $candidates = [];

            if (is_string($key)) {
                $candidates[] = $key;
            }
            if (is_string($item)) {
                $candidates[] = $item;
            }

            foreach ($candidates as $candidate) {
                $reason = self::REASON_SYNONYMS[mb_strtolower(trim($candidate))] ?? null;
                if ($reason !== null) {
                    $matched[$reason] = true;
                }
            }
        }

        return array_values(array_intersect(self::REASONS, array_keys($matched)));
    }
}
