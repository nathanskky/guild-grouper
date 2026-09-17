# guild/grouper

IU Grouper group-membership lookup for PHP.

This package answers one question — *which groups does this IU username belong to?* — by calling the IU
Grouper web services API.

**It is read-only.** There is no `addMember()`, `removeMember()`, `createGroup()` or anything like them,
and there will not be. Group membership is managed in Grouper itself. This library only reads.

Its consumer is the framework's authorization layer. It is not an app-developer-facing service, and it
takes no position on what an application should do when Grouper is unreachable — it reports the condition
and lets the caller decide.

## Requirements

- PHP `~8.5.0`
- A Grouper service account (username + password) authorised against the stem you configure.

## Installation

Add the repository, then require the package:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/nathanskky/guild-grouper.git"
        }
    ]
}
```

```bash
composer require guild/grouper:^1.0
```

## Configuration

```php
use Guild\Grouper\GrouperConfiguration;

$config = new GrouperConfiguration(
    serviceUrl: $_ENV['GROUPER_URL'],      // e.g. 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest'
    username:   $_ENV['GROUPER_USER'],
    password:   $_ENV['GROUPER_PASSWORD'],
    stem:       $_ENV['GROUPER_STEM'],     // optional; e.g. 'iu:roles:sys:acm'
);
```

| Field | Type | Description |
|-------|------|-------------|
| `serviceUrl` | `string` | Base URL of the Grouper REST service, **without** a version segment. Must be `https`; a trailing slash is stripped. |
| `username` | `string` | Service-account username, sent as HTTP Basic auth. |
| `password` | `string` | Service-account password. |
| `clientVersion` | `string` | Default `'v2_5_000'`. The Grouper *client* version — the API contract a client is coded against. See [Client version](#client-version). |
| `stem` | `?string` | Optional. The stem membership queries are scoped to. Blank or omitted means an **unscoped, institution-wide lookup** — every group the user belongs to. See [Choosing a stem](#choosing-a-stem). |

Invalid configuration throws `GrouperConfigurationException` at construction, so a misconfigured
deployment fails at boot rather than on the first authorization check.

### Client version

The version segment in a Grouper URL is the **client version** — which revision of the API contract your
client was written against. Grouper keeps older client versions working, so this is a compatibility
dial, not a per-endpoint detail: one value applies to every call.

```php
$config = new GrouperConfiguration(
    // ...
    clientVersion: 'v4_0_000',
);
```

The default is `v2_5_000` — the version IU's production .NET clients run against, and so the one
empirically known to work against IU's deployment. Grouper keeps older client versions working, so
moving to a newer contract is a configuration change rather than a code change.

> The published Swagger appears to show a different version per operation — `getGroupsLite` at
> `v4_0_440`, `findGroupsLite` at `v4_0_330`. It does not. Sorted by operation name, all 65 paths run
> `v4_0_010`, `v4_0_030` … `v4_0_660` in alphabetical order stepping by ten; they are generator sequence
> numbers, not versions.

### Choosing a stem

`stemName` is optional in Grouper's own API, and it is optional here. The trade-off:

- **With a stem**, the lookup is scoped to that subtree (`stemScope=ALL_IN_SUBTREE`) — faster, and it
  returns only groups the application has a reason to see.
- **Without one**, Grouper returns every group the user belongs to, institution-wide. Measured against a
  real IU account that is 357 groups and 5.6 seconds. Prefer a stem when you know one.

IU applications commonly query shared institutional stems rather than owning a subtree of their own —
`iu:roles:sys:acm` (ACM roles) and `iu:bundles` (compliance bundles) are the usual ones. In that model the
stem narrows the query and your group identifiers do the actual selecting.

A blank stem is normalised to `null` rather than rejected, so an unset `GROUPER_STEM` degrades to an
unscoped lookup instead of a boot failure. If a stem is important to your deployment, assert it yourself.

**One client is scoped to one stem.** To query two stems — ACM roles *and* bundles, say — construct two
clients, or configure no stem and filter by group identifier.

## Constructing the client

The Guzzle client is injected rather than built internally, so it can be configured with timeouts, a
proxy, or retries to suit the deployment:

```php
use GuzzleHttp\Client;
use Guild\Grouper\GrouperClient;

$client = new GrouperClient($config, new Client(['timeout' => 30]));
```

Set a timeout you are happy to have an authorization check wait for, and **measure before choosing a
small one**. An unscoped lookup against a real account with 357 groups took **5.6 seconds**; a stem-scoped
one is far quicker. Guzzle's default is no timeout at all, which makes an unresponsive Grouper
indistinguishable from a hung request.

## `groupsFor()`

```php
public function groupsFor(string $username): GroupMembership|GrouperUnavailable
```

Returns the groups `$username` belongs to within the configured stem.

**An empty group list is a successful answer**, not a failure. It means Grouper was reached and this user
belongs to nothing here — the ordinary case for most people and most applications. Do not conflate it with
`GrouperUnavailable`, which means the answer is unknown.

```php
$result = $client->groupsFor('jdoe');

if ($result instanceof GrouperUnavailable) {
    // Grouper could not be reached. Your policy decision, not this library's:
    // deny everything, serve a cached answer, or degrade to read-only.
    $logger->warning('Grouper unavailable', ['reason' => $result->reason]);

    return false;
}

// $result is a GroupMembership from here on.
return $result->belongsTo('iu:apps:your-app:editors');
```

The `instanceof` check is not optional politeness. PHPStan at level `max` rejects `->groups` on the
un-narrowed union, which is why the method returns a union rather than a result object.

## `groupExists()`

```php
public function groupExists(string $identifier): bool|GrouperUnavailable
```

Whether a group with this exact identifier exists. Useful when an administrator registers a group by
hand: a typo'd identifier is not an error anywhere else here, it simply matches nobody forever — which
looks exactly like a correctly configured group that happens to be empty.

`GrouperUnavailable` is deliberately not folded into `false`, because "this group does not exist" and "I
could not ask" should not be indistinguishable. Only one of them means somebody has to go fix something.

```php
$exists = $client->groupExists('iu:apps:your-app:editors');

if ($exists instanceof GrouperUnavailable) {
    // Unknown — do not tell the administrator their group is missing.
} elseif (! $exists) {
    // Genuinely no such group. Probably a typo.
}
```

## Return and error types

| Type | Meaning |
|------|---------|
| `GroupMembership` | A username and its `list<GrouperGroup>`. `belongsTo(string $identifier): bool` matches on the group's **system name**, not its display name. An empty list is valid. |
| `GrouperGroup` | One group: `identifier` (the system name, e.g. `iu:apps:x:editors`), `displayName`, `uuid`, and an optional `description`. |
| `GrouperUnavailable` | **Returned, not thrown.** Grouper could not be reached; membership is unknown. Carries `reason`, an optional `statusCode`, and the optional underlying `previous` throwable. |
| `GrouperConfigurationException` | **Thrown.** Extends `LogicException`. Bad configuration, or credentials Grouper rejected (401/403) — a deployment error a human must fix. |
| `GrouperResponseException` | **Thrown.** Extends `RuntimeException`. Grouper answered with something unrecognisable, or reported `success="F"`. Carries Grouper's `resultCode` and `resultMessage`. |

The rule behind that table: **conditions that may clear on their own are returned; conditions a human
must fix are thrown.**

Rejected credentials are thrown on purpose. Degrading them to a transient "unavailable" would let a
deployment run indefinitely with an authorization layer that silently denies everyone.

### How outcomes map

| Condition | Result |
|---|---|
| Connection failure, timeout | `GrouperUnavailable`, `statusCode` `null` |
| HTTP 429, or any 5xx | `GrouperUnavailable` with the status |
| HTTP 401, 403 | throws `GrouperConfigurationException` |
| HTTP 404 from `groupExists()` | `false` — a meaningful "no such group" |
| 200 with `success="T"` | `GroupMembership` (possibly empty) |
| 200 with `success="F"`, or an unrecognisable body | throws `GrouperResponseException` |

## Not in scope

- **Caching.** Every call hits Grouper. Caching policy belongs to the consumer.
- **Deciding what to do when Grouper is down.** This library reports reachability; the framework decides.
- **Group management.** Read-only, as above.
