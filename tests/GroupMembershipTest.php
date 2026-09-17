<?php

declare(strict_types=1);

namespace Guild\Grouper\Test;

use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupMembership::class)]
#[CoversClass(GrouperGroup::class)]
final class GroupMembershipTest extends TestCase
{
    /**
     * The single most important behaviour in this library. A user who belongs
     * to no groups in the configured stem is a completely ordinary answer —
     * most people at the institution are in that position for any given app.
     * Treating it as a failure would make "not authorized" indistinguishable
     * from "Grouper is down", which is exactly the distinction the framework's
     * authorization layer needs.
     */
    public function test_a_user_with_no_groups_is_a_valid_membership(): void
    {
        $membership = new GroupMembership('jdoe', []);

        self::assertSame('jdoe', $membership->username, 'the username is echoed back for logging');
        self::assertSame([], $membership->groups, 'no groups is an empty list, never null');
        self::assertFalse($membership->belongsTo('iu:apps:x:editors'), 'an empty membership belongs to nothing');
    }

    public function test_it_reports_membership_by_identifier(): void
    {
        $membership = new GroupMembership('jdoe', [
            new GrouperGroup('iu:apps:x:editors', 'Editors', 'uuid-1', 'App editors'),
        ]);

        self::assertTrue($membership->belongsTo('iu:apps:x:editors'));
        self::assertFalse($membership->belongsTo('iu:apps:x:admins'));
    }

    /**
     * belongsTo matches the system name, not the human-facing display name.
     * The two differ in separator and casing, and only the system name is
     * stable enough to register an authorization rule against.
     */
    public function test_it_does_not_match_on_display_name(): void
    {
        $membership = new GroupMembership('jdoe', [
            new GrouperGroup('iu:apps:x:editors', 'Editors', 'uuid-1', 'App editors'),
        ]);

        self::assertFalse($membership->belongsTo('Editors'), 'only the system name identifies a group');
    }

    public function test_group_description_is_optional(): void
    {
        $group = new GrouperGroup('iu:apps:x:editors', 'Editors', 'uuid-1');

        self::assertNull($group->description, 'Grouper groups need not carry a description');
    }
}
