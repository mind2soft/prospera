<?php

namespace Tests\Unit;

use App\Services\EnvelopeLimitChecker;
use PHPUnit\Framework\TestCase;

/**
 * The allocation-crossing decision of the "envelope limit exceeded"
 * notification (issue #52, docs/notifications.md) — pure arithmetic over
 * decrypted minor units. Sign convention (docs/budget.md): expense
 * allocations and transactions are negative; "remaining allocated" is
 * `allocated - recorded`, and the envelope is over when it is negative.
 */
class EnvelopeLimitCheckerTest extends TestCase
{
    private const ALLOCATED = -20_000; // -200.00

    public function test_a_within_allocation_recording_that_crosses_notifies(): void
    {
        // -100 recorded, then a -150 expense: remaining goes from +100 to -50.
        $this->assertTrue(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, -10_000, -15_000));
    }

    public function test_the_exact_boundary_of_the_allocation_is_not_over(): void
    {
        // Recorded exactly equals the allocation: remaining is zero —
        // within, not over.
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, self::ALLOCATED, 0));
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, 0, self::ALLOCATED));
    }

    public function test_the_first_minor_unit_past_the_boundary_crosses(): void
    {
        // -200 recorded, then -0.01: remaining goes from 0 to -0.01.
        $this->assertTrue(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, self::ALLOCATED, -1));
    }

    public function test_an_already_over_envelope_does_not_cross_again(): void
    {
        // -250 recorded (already over), any further expense never notifies.
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, -25_000, -5_000));
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, -25_000, -1));
    }

    public function test_a_recording_that_stays_within_the_allocation_does_not_cross(): void
    {
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, -5_000, -5_000));
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, -19_999, -1));
    }

    public function test_a_credit_that_pulls_spending_back_does_not_cross(): void
    {
        // A positive amount on the envelope reduces the recorded spending.
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, -10_000, 5_000));
    }

    public function test_an_under_spent_envelope_stays_within_even_after_a_credit(): void
    {
        $this->assertFalse(EnvelopeLimitChecker::isCrossing(self::ALLOCATED, -5_000, 10_000));
    }
}
