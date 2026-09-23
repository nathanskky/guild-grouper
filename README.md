# guild/grouper

IU Grouper group-membership lookup for PHP.

This package answers one question — *which groups does this IU username belong to?* — by calling the IU
Grouper web services API.

**It is read-only.** There is no `addMember()`, `removeMember()`, `createGroup()` or anything like them,
and there will not be. Group membership is managed in Grouper itself. This library only reads.

Its intended consumer is `guild/framework`'s authorization layer rather than application code directly,
but the API is small and documented here in full either way. It takes no position on what an application
should do when Grouper is unreachable — it reports the condition and lets the caller decide.

> **Pre-1.0.** The public API is verified against IU's production Grouper and the test suite is green,
> but the version is deliberately `0.x` pending a full code review. Treat minor releases as potentially
> breaking until `1.0.0`.

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
composer require guild/grouper:^0.1
```

## Configuration

```php
use Guild\Grouper\GrouperConfiguration;

$config = new GrouperConfiguration(
    serviceUrl: $_ENV['GROUPER_URL'],      // e.g. 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest'
    username:   $_ENV['GROUPER_USER'],
    password:   $_ENV['GROUPER_PASSWORD'],
    // stem defaults to 'iu:roles:sys:acm' (ACM-managed groups) -- usually
    // what you want, so you can leave it out entirely.
);
```

| Field | Type | Description |
|-------|------|-------------|
| `serviceUrl` | `string` | Base URL of the Grouper REST service, **without** a version segment. Must be `https`; a trailing slash is stripped. |
| `username` | `string` | Service-account username, sent as HTTP Basic auth. |
| `password` | `string` | Service-account password. |
| `clientVersion` | `string` | Default `'v2_5_000'`. The Grouper *client* version — the API contract a client is coded against. See [Client version](#client-version). |
| `stem` | `?string` | Defaults to `GrouperConfiguration::ACM_STEM` (`iu:roles:sys:acm`). `null` **or a blank string** means an unscoped, institution-wide lookup — so `stem: $_ENV['GROUPER_STEM'] ?? ''` with the variable unset silently gives you the slow query, not the default. Omit the argument instead. See [Choosing a stem](#choosing-a-stem). |

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
empirically known to work against IU's deployment.

**In practice IU's Grouper does not appear to validate this at all.** A deliberately bogus `v9_9_999`
returned a complete, correct result. The setting is kept because a future Grouper may begin enforcing it
and it costs nothing to carry, but do not treat it as a working compatibility dial today.

> The published Swagger appears to show a different version per operation — `getGroupsLite` at
> `v4_0_440`, `findGroupsLite` at `v4_0_330`. It does not. Sorted by operation name, all 65 paths run
> `v4_0_010`, `v4_0_030` … `v4_0_660` in alphabetical order stepping by ten; they are generator sequence
> numbers, not versions.

### Choosing a stem

**The default is `iu:roles:sys:acm`, and most applications should leave it alone.**

ACM — Access Control Management — is the tool IU users administer group membership with. Groups created
and managed there live under that stem, which makes them precisely what an application's access-control
check is asking about. The other institutional stems hold different things: `iu:bundles` is compliance
bundles, `iu:entlmt:app` is entitlements. Neither is what "may this person edit content" means.

Measured against one real IU account, 183 of its 357 groups were ACM groups, and the namespace is flat —
every one sat exactly one level below the stem.

```php
// The default: ACM-managed groups.
new GrouperConfiguration(serviceUrl: $url, username: $u, password: $p);

// A different stem, if you genuinely need one.
new GrouperConfiguration(serviceUrl: $url, username: $u, password: $p, stem: 'iu:bundles');

// Unscoped: every group, institution-wide. Slow -- see below.
new GrouperConfiguration(serviceUrl: $url, username: $u, password: $p, stem: null);
```

An unscoped lookup returns every group the user belongs to anywhere at the institution, which is both
slow and mostly irrelevant to an access-control question.

**Lookups use `stemScope=ONE_LEVEL`**, matching IU's production clients, because ACM's namespace is flat.
That makes the stem you choose an exact-depth match, not a subtree search — which is the single most
important thing to understand here. Measured against one real IU account:

| `stem` | Groups returned | Notes |
|---|---:|---|
| *(default)* `iu:roles:sys:acm` | **183** | ACM-managed groups — what you want |
| `iu:bundles` | 20 | Also flat, so it behaves |
| `iu:roles:sys` | **0** | A *parent* of the ACM stem. Its 187 groups all sit one level deeper |
| `iu:entlmt:app` | **0** | Same trap — its 144 groups sit deeper |
| `null` *(unscoped)* | 357 | Everything, everywhere |

The unscoped lookup took **5618 ms**; scoped lookups were consistently sub-second. That speed difference
is the second reason to keep the default, after correctness.

Two ways a stem can disappoint you quietly:

- **A stem whose groups sit deeper than one level returns nothing, successfully.** Not an error — a
  `success="T"` response with no groups at all, indistinguishable from a user who genuinely has none. The
  `iu:roles:sys` and `iu:entlmt:app` rows above are exactly this. **If a custom stem yields empty
  memberships for everyone, check its depth before anything else.**
- **A stem that does not exist is an error**, not an empty result. Grouper answers `400 INVALID_QUERY`
  with "Stem not found", and `groupsFor()` throws `GrouperResponseException`.

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

**An empty group list is a successful answer**, not a failure — verified against production, where
Grouper reports it as `success="T"` with the `wsGroups` key absent entirely. It means Grouper was reached and this user
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
return $result->belongsTo('iu:roles:sys:acm:your-app-editors');
```

The `instanceof` check is not optional politeness. PHPStan at level `max` rejects `->groups` on the
un-narrowed union, which is why the method returns a union rather than a result object.

## `groupExists()`

```php
public function groupExists(string $identifier): bool|GrouperUnavailable
```

Whether a group with this exact identifier exists. Sent as a JSON `WsRestFindGroupsRequest`, unlike
`groupsFor()`, which uses the form-encoded Lite shape — the two operations genuinely differ.

Useful when an administrator registers a group by
hand: a typo'd identifier is not an error anywhere else here, it simply matches nobody forever — which
looks exactly like a correctly configured group that happens to be empty.

`GrouperUnavailable` is deliberately not folded into `false`, because "this group does not exist" and "I
could not ask" should not be indistinguishable. Only one of them means somebody has to go fix something.

```php
$exists = $client->groupExists('iu:roles:sys:acm:your-app-editors');

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
| `GrouperGroup` | One group: `identifier` (the system name, e.g. `iu:roles:sys:acm:your-app-editors`), `displayName` (the full display path), `displayExtension` (the short label ACM shows, e.g. `Your App Editors`; derived from the last `displayName` segment if Grouper omits it), `uuid`, and an optional `description`. Match on `identifier` only — the display fields are mutable and not unique. |
| `GrouperUnavailable` | **Returned, not thrown.** Grouper could not be reached; membership is unknown. Carries `reason`, an optional `statusCode`, and the optional underlying `previous` throwable. |
| `GrouperConfigurationException` | **Thrown.** Extends `LogicException`. Bad configuration, or credentials Grouper rejected (401/403) — a deployment error a human must fix. |
| `GrouperResponseException` | **Thrown.** Extends `RuntimeException`. Grouper answered with something unrecognisable, or reported `success="F"`. Carries Grouper's `resultCode` and `resultMessage`. |

The rule behind that table: **conditions that may clear on their own are returned; conditions a human
must fix are thrown.**

Rejected credentials are thrown on purpose. Degrading them to a transient "unavailable" would let a
deployment run indefinitely with an authorization layer that silently denies everyone.

### How outcomes map

Both methods share the transport rules:

| Condition | Result |
|---|---|
| Connection failure, timeout | `GrouperUnavailable`, `statusCode` `null` |
| HTTP 429, or any 5xx | `GrouperUnavailable` with the status |
| HTTP 401, 403 | throws `GrouperConfigurationException` |
| An unrecognisable body | throws `GrouperResponseException` |

`groupsFor()` then adds:

| Condition | Result |
|---|---|
| `success="T"` | `GroupMembership` — possibly empty, which is a valid answer |
| `404` `SUBJECT_NOT_FOUND` | **empty `GroupMembership`** — see [Unknown users](#unknown-users) |
| Any other `success="F"` | throws `GrouperResponseException` |

`groupExists()` then adds:

| Condition | Result |
|---|---|
| `success="T"` with `groupResults` | `true` |
| `success="T"` with no `groupResults` key | `false` — this is how Grouper reports "no such group" |
| Any `success="F"` | throws `GrouperResponseException` |

### Unknown users

When the username is not a subject in Grouper at all — a deprovisioned account, a guest, or a typo —
Grouper answers `404` with `resultCode=SUBJECT_NOT_FOUND`. `groupsFor()` reports that as an **empty
`GroupMembership`**, not an exception.

The reasoning is blast radius. An authorization check denies on an empty membership, which is the right
outcome for a user Grouper has never heard of. Throwing would turn a deprovisioned account hitting the
app into a `500` rather than a clean denial.

The cost is that a typo'd username is indistinguishable from a real user with no groups. If you need to
tell them apart — an admin screen that assigns access by username, say — check the username against your
identity source before asking Grouper about it.

Every *other* failure code still throws. A stem that does not exist answers `400 INVALID_QUERY`
("Stem not found"), which is a misconfiguration somebody must fix, not a user with no groups.

## Not in scope

- **Caching.** Every call hits Grouper. Caching policy belongs to the consumer.
- **Deciding what to do when Grouper is down.** This library reports reachability; the framework decides.
- **Group management.** Read-only, as above.
