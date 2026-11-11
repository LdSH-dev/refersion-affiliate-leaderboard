<?php

namespace Tests\Unit;

use App\Domain\Affiliate;
use App\Domain\EligibilityPolicy;
use App\Domain\Money;
use App\Refersion\RefersionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    #[Test]
    public function eligibility_follows_the_brief_literally(): void
    {
        $flag = fn (mixed ...$values) => array_map(fn ($v) => ['label' => 'Display in leaderboard', 'value' => $v], $values);

        $this->assertTrue(EligibilityPolicy::isEligible([['label' => 'Website', 'value' => 'x'], ...$flag('TRUE')]));
        $this->assertFalse(EligibilityPolicy::isEligible($flag('true')));
        $this->assertFalse(EligibilityPolicy::isEligible($flag(' TRUE')));
        $this->assertFalse(EligibilityPolicy::isEligible([['label' => 'display in leaderboard', 'value' => 'TRUE']]));
        $this->assertFalse(EligibilityPolicy::isEligible([]));
        $this->assertFalse(EligibilityPolicy::isEligible($flag('TRUE', 'FALSE')), 'conflicting duplicates');
    }

    #[Test]
    public function money_stays_exact(): void
    {
        $this->assertSame('1500.00', Money::normalize(1500));
        $this->assertSame('1520.75', Money::normalize(1520.75));
        $this->assertSame('9007199254740993.10', Money::normalize('9007199254740993.10'));
        $this->assertNull(Money::normalize('1,234.00'));
        $this->assertSame(1, Money::compare('9007199254740993.00', '9007199254740992.00'));
        $this->assertSame('-1,234,567.50', Money::format('-1234567.5'));
    }

    #[Test]
    public function affiliates_are_read_defensively(): void
    {
        $this->assertSame('Acme', Affiliate::fromPayload(['id' => 7, 'firstName' => '', 'company' => 'Acme'])->name);
        $this->assertSame('0.00', Affiliate::fromPayload(['id' => 7])->commission, 'no performance data');
        $this->assertNull(Affiliate::fromPayload(['id' => 7, 'performance' => ['total' => ['commission' => '+5']]])->commission);

        $this->expectException(RefersionException::class);
        Affiliate::fromPayload(['id' => null]);
    }
}
