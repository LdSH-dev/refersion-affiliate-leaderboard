<?php

namespace App\Domain;

/**
 * Commission amounts as decimal strings compared with bcmath, never as floats.
 */
final class Money
{
    /**
     * "1500" → "1500.00", 1520.75 → "1520.75". Null when the value is not a number.
     *
     * A float from json_decode is printed with the shortest form that round-trips (what
     * json_encode does), which is the literal the API sent.
     */
    public static function normalize(mixed $value): ?string
    {
        if (is_float($value) && is_finite($value)) {
            $value = json_encode($value);
            $value = str_contains($value, 'e') ? number_format((float) $value, 2, '.', '') : $value;
        }

        $decimal = match (true) {
            is_int($value) => (string) $value,
            is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 => $value,
            default => null,
        };

        if ($decimal === null) {
            return null;
        }

        // bcadd with the input's own scale canonicalizes ("007.5" → "7.50") without rounding.
        $normalized = bcadd($decimal, '0', max(2, self::scale($decimal)));

        return bccomp($normalized, '0', self::scale($normalized)) === 0 ? '0.00' : $normalized;
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, max(self::scale($a), self::scale($b)));
    }

    /** "-1234567.5" → "-1,234,567.50". */
    public static function format(string $amount): string
    {
        [$int, $fraction] = explode('.', self::normalize($amount) ?? '0.00', 2);
        $sign = str_starts_with($int, '-') ? '-' : '';

        return $sign.strrev(implode(',', str_split(strrev(ltrim($int, '-')), 3))).'.'.$fraction;
    }

    private static function scale(string $decimal): int
    {
        return strlen(explode('.', $decimal, 2)[1] ?? '');
    }
}
