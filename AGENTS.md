# AGENTS.md

Guidance for AI coding agents (and new humans) working in this repository.

## What this is

`guild/grouper` — a small, read-only client for the IU Grouper web services API. It answers one question:
which groups does this IU username belong to? Namespace `Guild\Grouper\`, autoloaded from `src/`.
Consumed as a Composer dependency; not runnable on its own.

- **PHP:** `~8.5.0`. This constraint is load-bearing — `guild/framework` requires `~8.5.0`, so a package
  that excludes 8.5 cannot be installed alongside it.
- **Remote:** `https://github.com/nathanskky/guild-grouper.git`
- **Default branch:** `develop`. **Current release: `v0.1.2`**, tagged on `main`.
- **This package is pre-1.0 on purpose.** Every behaviour is verified against production Grouper, but the
  version stays `0.x` until a full code review has happened. Until then, treat minor releases as
  potentially breaking and consume it as `^0.1`.
- **`composer.lock` is gitignored** here, so there is no lock to keep in sync.

**Scope is deliberately narrow, and the narrowness is the point.** This package looks up group membership
and does nothing else. It is **read-only**: no create, update, delete, add-member or remove-member, ever.
Group management happens in Grouper itself. Caching, and the policy for what an application does when
Grouper is unreachable, both belong to the consumer — this library reports the condition and stops there.

> **`README.md` is the authoritative reference for this library's public API** — configuration fields,
> all three methods, the return unions, and the full outcome table. Read it before touching
> `src/GrouperClient.php`. This file covers *developing* the package; the README covers *using* it.

## Sibling packages

These repos are developed side by side but are **independent git repos**. There is no root
`composer.json` and no root git repository. Do not invent root-level tooling or a shared autoloader.

| Package | Namespace | Role |
|---|---|---|
| `guild/grouper` *(this one)* | `Guild\Grouper\` | Grouper group-membership lookup |
| `guild/access` | `Guild\Access\` | IU Login (OIDC) authentication. Independent of this package |
| `guild/framework` | `Guild\Framework\` | Application kernel / DI container. **Requires this package** (`^0.1`, via VCS repo); its authorization layer is the only consumer |
| `guild/starter` | `Guild\Starter\` | Runnable example app |
| `guild/rivet` | `Guild\Rivet\` | IU Rivet Design System components. Independent |

This package has **no first-party dependencies**. Authentication (`guild/access`) and authorization are
separate concerns in separate packages; do not merge them.

## Verify your change

Dependencies are not installed in a fresh clone, and `vendor/bin/` is empty until you install.

```bash
composer install       # required first
composer test          # phpunit, suite "guild-grouper"
composer analyse       # phpstan, level max, scoped to src/
composer format:check  # pint, PSR-12 style check (writes nothing)
composer check         # test, then analyse, then style check; stops at the first failure
```

**This package is fully green. Keep it that way.** There is no baseline to take and no pre-existing
failure to work around — any failure is yours.

PHPStan runs at level `max`, the strictest setting in the workspace (`framework` is 10, `notification` is
5). Do not assume one bar across the repos.

`phpunit.xml.dist` follows the framework's strictness rather than `access`'s: `requireCoverageMetadata`
and `beStrictAboutCoverageMetadata` are on, so **a test class without `#[CoversClass]` fails the run**.

## Architecture

```
src/
├── GrouperConfiguration.php   final readonly · validates on construction
├── GrouperClient.php          final readonly · the only public entry point
├── GrouperGroup.php           final readonly · one group, flattened from WsGroup
├── GroupMembership.php        final readonly · a username + its groups
├── GrouperUnavailable.php     final readonly · a returned value, NOT a Throwable
└── Exception/
    ├── GrouperConfigurationException.php   final · extends LogicException
    └── GrouperResponseException.php        final · extends RuntimeException
```

Layout is **flat**. There are seven classes and no reason for a directory tree; do not add one.

**The single design decision everything else follows from:** conditions that may clear on their own are
**returned**; conditions a human must fix are **thrown**.

- Unreachable Grouper → `GrouperUnavailable`, a plain value. It is not a `Throwable` on purpose, so the
  caller cannot accidentally let it sail past them. Fail-open vs. fail-closed is the consumer's policy.
- Bad configuration, or credentials Grouper rejects (401/403) → `GrouperConfigurationException`.
- An unrecognisable response, or `success="F"` → `GrouperResponseException`.

**Why a union and not a result object:** PHPStan at level `max` rejects `->groups` on the un-narrowed
`GroupMembership|GrouperUnavailable`, with `property.notFound`. That enforcement is the entire
justification for the union; it has been verified to hold. If you ever drop the PHPStan level, you lose
the guarantee that callers handle the unavailable case.

**Not named `Group`** — `guild/framework` already has an Eloquent model by that name. Keep the `Grouper`
prefix on anything that could collide.

### Test conventions

`tests/`, namespaced `Guild\Grouper\Test\`. Follow `access/tests/` — it is the reference suite for the
workspace. In short: `final class XxxTest extends TestCase`, attributes only (`#[CoversClass]`,
`#[DataProvider]`), static assertions with a third-argument message explaining intent, long prose test
method names, `public static` data providers returning `iterable` with `'label' => [...]`, and fixtures
as private instance helper methods.

**No PHPUnit mocks.** HTTP is faked with Guzzle's own `MockHandler` plus `Middleware::history()` to
capture the outgoing request; see `clientReturning()` and `clientThrowing()` in `GrouperClientTest`.

## Landmines

These are the things about Grouper's API that are easy to get wrong. All of them are handled in one place
in `GrouperClient`; keep them there.

- **Grouper routes on URL path segments.** Each operation is *addressed*, not merely named in the body.
  `groupsFor()` posts to `{version}/subjects/{subject}/groups`; `groupExists()` and `findByLabel()` post
  to `{version}/groups`. Get it wrong and Grouper answers `INVALID_QUERY` telling you which segment it
  expected — `/groups` wants a following `members` or `memberships`, `/subjects` wants `groups` or
  `memberships`. This cost a full round of live debugging; do not re-derive it.
- **The two operations use different transports, and that is correct.** `groupsFor()` sends the
  form-encoded Lite shape and names itself in `wsLiteObjectType`. `groupExists()` sends a **JSON body**
  and names itself in the wrapper key `WsRestFindGroupsRequest`. Both are verified against production.
  Do not "fix" the inconsistency by unifying them — the form-encoded shape does not work for findGroups,
  and that is how the bug that shipped in `groupExists()` originally happened.
- **`FIND_BY_EXACT_ATTRIBUTE` rejects `stemNameScope`.** `findByLabel()` sends `stemName` alone; adding
  `stemNameScope` (either value) makes production answer `400 INVALID_QUERY`. `stemName` alone searches
  the **whole subtree**, unlike `groupsFor()`'s `ONE_LEVEL`, so `findByLabel()` drops matches deeper than
  one level itself. Do not "restore" the scope parameter, and do not remove the depth filter.
- **Success is signalled by `resultMetadata.success`**, a string `"T"`/`"F"`, **not by the HTTP status
  alone.** A 200 can carry a failure, and a 404 can carry a result you want (`SUBJECT_NOT_FOUND`).
  Never infer success from the status code.
- **Grouper reports "nothing found" by omitting the key entirely.** No `wsGroups`, no `groupResults` —
  not an empty array. `groupsFrom()` treats an absent key and an empty array alike, which is why it
  works; keep that property if you touch it.
- **The version segment in the path is the *client* version, not a per-endpoint version.** It is the API
  contract the client is coded against, and it is one value for every operation —
  `GrouperConfiguration::$clientVersion`, defaulting to `v2_5_000`, the version IU's production .NET
  clients run against. **IU's Grouper does not appear to validate it**: a bogus `v9_9_999` returned a
  complete, correct result. It is kept in case that changes.

  **The published Swagger makes the versioning look per-operation, and it is wrong.** It documents
  `getGroupsLite` at `v4_0_440` and `findGroupsLite` at `v4_0_330`. Sort all 65 paths by `operationId`
  and they run `v4_0_010`, `v4_0_030`, `v4_0_040` … `v4_0_660` — strict alphabetical order, stepping by
  ten. Those are synthetic sequence numbers from the doc generator, not versions; `getGroupsLite` is
  simply the 44th operation alphabetically. Keep the version in configuration; it does not belong in
  per-operation constants.
- **`wsLiteObjectType` is required for the form-encoded shape, but its documented *value* is
  untrustworthy.** Omit it and `groupsFor()` fails with a 500, "Invalid POST request" — the parameter is
  real. But all 29 Lite operations in the Swagger declare the identical description
  `WsRestFindGroupsLiteRequest`, `addMemberLite` included, so the generator clearly lost the
  per-operation value. This library sends what each operation's own request class implies. If a live call
  is answered by the *wrong* operation, suspect these strings.
- **`groupName` cannot be combined with other search parameters** in findGroups, per Grouper's own
  parameter documentation. This is why `groupExists()` does not send `stemName` and expects fully
  qualified identifiers.
- **A blank stem normalises to `null`, which means unscoped.** So `stem: $_ENV['GROUPER_STEM'] ?? ''`
  with the variable unset silently gives a 5.6-second institution-wide query instead of the ACM default.
  Omitting the argument is the safe way to get the default.

**On stem conventions:** IU applications query shared *institutional* stems, not per-application
subtrees. Do not expect an `iu:apps:your-app` model.

**`iu:roles:sys:acm` is the one that matters, and it is the default.** ACM — Access Control Management —
is the tool IU users administer group membership with, so ACM-managed groups are what an application
authorises against. The other stems the .NET clients hard-code hold different things: `iu:bundles` is
compliance bundles, `iu:entlmt:app` is entitlements. Neither answers "may this person edit content".

Measured on one real account: 183 of 357 groups under `iu:roles:sys:acm`, 4 under `iu:roles:sys:acmex`,
and the ACM namespace is **flat** — all 183 exactly one level below the stem. (183 + 4 = 187, which is
what a *subtree* search of `iu:roles:sys` returns. A `ONE_LEVEL` search of that same parent stem returns
**zero** — see the landmine below.)

One `GrouperClient` is scoped to one stem; two stems means two clients, or `stem: null` plus filtering by
identifier.

**`stemScope` is `ONE_LEVEL`**, matching IU's production .NET clients, whose authors know ACM well. ACM's
namespace is flat, so one level is the precise scope for the default stem.

**The landmine that comes with it:** against a stem whose groups live deeper than one level, `ONE_LEVEL`
returns nothing *successfully* — `success="T"`, no groups, no error. This is observed, not theoretical: a
scoped lookup on `iu:roles:sys` returns zero groups for an account with 187 of them, because they all
really sit under `iu:roles:sys:acm`. If a configured stem starts returning empty memberships for
everybody, check its depth before anything else.

## Reference material

The authority is the official Grouper WS v4 Swagger specification, which is public and needs no
authentication:

```
https://grouperws.apps.iu.edu/grouper-ws/docs/index.json
```

Re-fetch it rather than trusting a restatement, including this one.

**Do not port the .NET clients' transport** (`SP3.Grouper.Api`, `EA.Grouper.Api.Client`). They issue `GET`
with a JSON body and name the operation in a `WsRest…Request` wrapper inside that body — a request shape
that appears nowhere in the v4 specification, which is POST-only and form-encoded for the Lite variants.

They are worth reading for three things, and this library takes all three: the client version belongs in
the base URL once rather than per operation, the operation is named in the request rather than the path,
and Grouper's envelopes are worth flattening into small DTOs. Their `v2_5_000` is also where this
library's default client version comes from — it is the version IU's deployment is known to accept.

## Verification status

**Every behaviour this library commits to has been exercised against IU's production Grouper** (server
`4.24.0`) with a real service account. Confirmed:

| Condition | Grouper's answer |
|---|---|
| Subject with groups | `200`, `success="T"`, `wsGroups` populated |
| Subject with no matching groups | `200`, `success="T"`, **`wsGroups` absent entirely** |
| Nonexistent subject | `404`, `success="F"`, `SUBJECT_NOT_FOUND` — reported as an empty membership |
| Nonexistent stem | `400`, `success="F"`, `INVALID_QUERY` / "Stem not found" |
| Nonexistent group (findGroups) | `200`, `success="T"`, `groupResults` absent |
| Label search (`FIND_BY_EXACT_ATTRIBUTE` on `displayExtension`) with `stemName` | `200`, the match; also found from the parent stem, so the search is subtree-wide |
| Label search with `stemNameScope` added | `400`, `INVALID_QUERY` |
| Label search with no stem | `200`, the match |
| Label search, no such label | `200`, `success="T"`, `groupResults` absent |
| Missing `wsLiteObjectType` | `500`, "Invalid POST request" |
| Bogus client version | `200` and a correct result — the segment is not validated |

The empty-membership case is **observed, not inferred** — a real subject against a real stem at
`ONE_LEVEL` where every matching group sits deeper. That is the behaviour the whole design rests on, so
it was worth forcing rather than assuming.

**The one thing still fixture-driven is Grouper being *down*.** `GrouperUnavailable` — connect failures,
429s, 5xx — cannot be produced on demand against a healthy service, so those paths are proven only by
`MockHandler`. That is precisely the shape of evidence that hid the original `groupExists()` bug, so
treat that branch with more suspicion than the rest.

### Re-verifying after a Grouper upgrade

Verification needs no application: this package has no first-party dependencies, so a standalone script
calling it directly is the shortest path to the wire. **Do not route verification through `starter` or
Docker** — every layer in between is somewhere a failure can hide.

`.env.grouper.local.example` records the environment variables such a script needs. Copy it to
`.env.grouper.local`, which the existing `*.env*` rule already gitignores — confirm with
`git check-ignore -v .env.grouper.local` before putting a password in it.

## Fixtures

**Every fixture is a real recorded response from IU's production Grouper (server 4.24.0), with group and
person identities anonymised.** Keep both properties when re-recording: the shape must stay real, and the
identities must not. These files are in a public repository, and a raw response describes one person's
institutional access.

`membership-two-groups.json` carries the full recorded envelope — the nine-field `WsGroup`, the
five-field `wsSubject`, and the real `resultMetadata`/`responseMetadata`. Its second group deliberately
omits `description`, because 4 of 357 groups in the recorded response had none; that is the case
`GrouperGroup::$description` being nullable exists for.

`membership-no-groups.json` and `group-not-found.json` both omit their results key entirely rather than
carrying an empty array, because that is what Grouper actually sends. Do not "tidy" them into `[]`.

## Branching and pull requests

**Do not commit directly to `develop` or `main`.** Work on a feature branch and open a pull request
against `develop`.

Tags live on `main`, never on `develop`:

```
feature branch  --PR-->  develop  --PR-->  main  --> tag (release)
```

Consumers require tagged versions, so **"merged into `develop`" and "released" are two different
states**. A consumer that cannot see your change has almost always hit exactly that. `guild/framework`
requires `^0.1`, so a change reaches it only after a new tag is cut on `main` — merging to `develop` is
not enough.

For a local iteration loop, temporarily add a path repository to the consumer's `composer.json` above its
VCS entries, and revert it before committing:

```json
{ "type": "path", "url": "../grouper", "options": { "symlink": true } }
```

**Never hand-edit `starter/vendor/guild/grouper/`** — the next `composer install` reverts it.
