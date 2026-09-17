<?php

declare(strict_types=1);

namespace Guild\Grouper\Test;

use Guild\Grouper\GrouperUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(GrouperUnavailable::class)]
final class GrouperUnavailableTest extends TestCase
{
    /**
     * Deliberately not a Throwable. Reachability is a condition the caller
     * decides a policy about (fail closed, serve stale, degrade), so it is
     * returned as a value rather than thrown past them.
     */
    public function test_it_is_a_value_and_not_throwable(): void
    {
        self::assertNotInstanceOf(\Throwable::class, new GrouperUnavailable('timed out'));
    }

    public function test_it_carries_the_reason_and_optional_detail(): void
    {
        $cause = new RuntimeException('connection refused');
        $unavailable = new GrouperUnavailable('transport failure', 503, $cause);

        self::assertSame('transport failure', $unavailable->reason);
        self::assertSame(503, $unavailable->statusCode);
        self::assertSame($cause, $unavailable->previous, 'the original error is kept for logging');
    }

    public function test_status_code_and_previous_are_optional(): void
    {
        $unavailable = new GrouperUnavailable('could not connect');

        self::assertNull($unavailable->statusCode, 'a connection that never landed has no HTTP status');
        self::assertNull($unavailable->previous);
    }
}
