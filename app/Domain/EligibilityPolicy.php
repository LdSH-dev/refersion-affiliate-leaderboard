<?php

namespace App\Domain;

/**
 * The brief's rule, applied literally: a custom field labelled "Display in leaderboard"
 * whose value is exactly the string "TRUE" (case-sensitive, no trimming).
 */
final class EligibilityPolicy
{
    public const LABEL = 'Display in leaderboard';

    public const VALUE = 'TRUE';

    /**
     * If the label appears more than once, every occurrence must be "TRUE": conflicting
     * values are an ambiguous opt-in, and the safe reading of an opt-in is "no".
     *
     * @param  list<mixed>  $customFields  raw records from GET /affiliates/{id}/custom-fields
     */
    public static function isEligible(array $customFields): bool
    {
        $values = [];
        foreach ($customFields as $field) {
            if (is_array($field) && ($field['label'] ?? null) === self::LABEL) {
                $values[] = $field['value'] ?? null;
            }
        }

        return $values !== [] && array_all($values, fn (mixed $value) => $value === self::VALUE);
    }
}
