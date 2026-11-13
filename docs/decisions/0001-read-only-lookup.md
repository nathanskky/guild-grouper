# 1. guild/grouper only reads, and doesn't cache

## Context

Apps need to know which Grouper groups a person is in, to decide what they may do. Grouper also
supports creating groups and changing membership, but IU manages membership in Grouper and ACM, not
through apps. Lookups are slow enough that an app wants a cache, and Grouper is sometimes unreachable.
What an app does about a slow or missing answer differs by app: one denies, another serves a cached
answer, a third shows an error.

## Decision

We will keep guild/grouper to read-only lookups. It has no method that creates, changes or deletes a
group or a membership. It doesn't cache, and it doesn't decide what an app does when Grouper is down. It
reports that condition as `GrouperUnavailable` and leaves the policy to the caller.

## Consequences

The package stays small, and it can't change anyone's access by mistake. Each consumer must bring its own
cache and outage policy; in a Guild app, the framework's membership cache provides both. An app that needs
to manage groups must use Grouper or ACM directly.
