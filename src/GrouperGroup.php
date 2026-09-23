<?php

declare(strict_types=1);

namespace Guild\Grouper;

/**
 * One Grouper group, flattened from the API's WsGroup envelope down to the
 * fields an authorization decision, or a person looking at one, actually needs.
 *
 * Named GrouperGroup rather than Group because guild/framework already has an
 * Eloquent model called Group; the two are unrelated and must not collide.
 */
final readonly class GrouperGroup
{
    /**
     * @param  string  $identifier  The system name, e.g. 'iu:apps:x:editors'. This is
     *                              WsGroup.name, and it is the only field stable enough to
     *                              register an authorization rule against.
     * @param  string  $displayName  WsGroup.displayName — the full display path, e.g.
     *                               'Indiana University:Applications:X:Editors'. For humans, never for matching.
     * @param  string  $displayExtension  WsGroup.displayExtension — the short label ACM shows, e.g.
     *                                    'Editors'. For humans, never for matching: it is mutable and
     *                                    not unique.
     * @param  string  $uuid  WsGroup.uuid — Grouper's own identifier, survives a rename.
     */
    public function __construct(
        public string $identifier,
        public string $displayName,
        public string $displayExtension,
        public string $uuid,
        public ?string $description = null,
    ) {
    }
}
