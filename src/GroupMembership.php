<?php

declare(strict_types=1);

namespace Guild\Grouper;

/**
 * The groups one username belongs to, within the configured stem.
 *
 * An empty group list is a valid, successful answer — it means Grouper was
 * reached and the user belongs to nothing here. Callers must not conflate it
 * with GrouperUnavailable, which means Grouper could not be reached and the
 * answer is unknown.
 */
final readonly class GroupMembership
{
    /**
     * @param  list<GrouperGroup>  $groups
     */
    public function __construct(
        public string $username,
        public array $groups,
    ) {
    }

    /**
     * Matches on the group's system name (WsGroup.name), not its display name.
     */
    public function belongsTo(string $identifier): bool
    {
        foreach ($this->groups as $group) {
            if ($group->identifier === $identifier) {
                return true;
            }
        }

        return false;
    }
}
