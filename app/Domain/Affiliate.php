<?php

namespace App\Domain;

use App\Refersion\RefersionException;

/**
 * The slice of a Refersion affiliate the leaderboard needs.
 */
final readonly class Affiliate
{
    public function __construct(
        public int $id,
        public string $name,
        /** Lifetime commission as a decimal string (see Money); null when unreadable. */
        public ?string $commission,
    ) {}

    /** Builds an affiliate from one raw GET /affiliates record. */
    public static function fromPayload(mixed $record): self
    {
        $id = is_array($record) ? ($record['id'] ?? null) : null;
        $id = is_string($id) && ctype_digit($id) ? (int) $id : $id;

        if (! is_int($id) || $id <= 0) {
            throw RefersionException::badResponse('An affiliate record has no valid "id".');
        }

        // performance is optional in the API: no data means no commission yet.
        $commission = Money::normalize($record['performance']['total']['commission'] ?? 0);

        return new self($id, self::name($record, $id), $commission);
    }

    /** Ranking order: commission descending, then id ascending so ties are deterministic. Readable commissions only. */
    public static function compare(self $a, self $b): int
    {
        return Money::compare((string) $b->commission, (string) $a->commission) ?: $a->id <=> $b->id;
    }

    /** "First Last", else the company, else "Affiliate #id". */
    private static function name(array $record, int $id): string
    {
        $text = fn (string $key) => is_string($record[$key] ?? null) ? trim($record[$key]) : '';

        return trim($text('firstName').' '.$text('lastName')) ?: ($text('company') ?: "Affiliate #{$id}");
    }
}
