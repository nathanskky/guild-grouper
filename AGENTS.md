# AGENTS.md

Guidance for AI coding agents (and new humans) working in this repository.

## What this is

`guild/grouper` — a small, read-only client for the IU Grouper web services API. It answers one question:
which groups does this IU username belong to? Namespace `Guild\Grouper\`, autoloaded from `src/`.
Consumed as a Composer dependency; not runnable on its own.

- **PHP:** `~8.5.0`. This constraint is load-bearing — `guild/framework` requires `~8.5.0`, so a package
  that excludes 8.5 cannot be installed alongside it.
- **Remote:** `https://github.com/nathanskky/guild-grouper.git`
- **Default branch:** `develop`.
- **`composer.lock` is gitignored** here, so there is no lock to keep in sync.

**Scope is deliberately narrow, and the narrowness is the point.** This package looks up group membership
and does nothing else. It is **read-only**: no create, update, delete, add-member or remove-member, ever.
Group management happens in Grouper itself. Caching, and the policy for what an application does when
Grouper is unreachable, both belong to the consumer — this library reports the condition and stops there.

> **`README.md` is the authoritative reference for this library's public API** — configuration fields,
> both methods, the return unions, and the full outcome table. Read it before touching
> `src/GrouperClient.php`. This file covers *developing* the package; the README covers *using* it.

## Sibling packages

These repos are developed side by side but are **independent git repos**. There is no root
`composer.json` and no root git repository. Do not invent root-level tooling or a shared autoloader.

| Package | Namespace | Role |
|---|---|---|
| `guild/grouper` *(this one)* | `Guild\Grouper\` | Grouper group-membership lookup |
| `guild/access` | `Guild\Access\` | IU Login (OIDC) authentication. Independent of this package |
| `guild/framework` | `Guild\Framework\` | Application kernel / DI container. The intended consumer |
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

These are the things about Grouper's API that are easy to get wrong. All three are handled in one place
in `GrouperClient`; keep them there.

- **Every Grouper v4 operation is POST.** All 65 paths in the specification are POST — there is no GET
  operation anywhere. The Lite variants take **form-encoded** parameters (`form_params` in Guzzle),
  **never a JSON body**. If you find yourself writing `'json' =>`, stop.
- **Success is signalled by `resultMetadata.success`**, a string `"T"`/`"F"`, **not by the HTTP status
  alone.** A 200 can carry a failure. Never infer success from the status code.
- **The version segment in the path is the *client* version, not a per-endpoint version.** It is the API
  contract the client is coded against, Grouper uses it for backwards compatibility, and it is one value
  for every operation — `GrouperConfiguration::$clientVersion`, defaulting to `v2_5_000`, the version
  IU's production .NET clients run against.

  **The published Swagger makes this look otherwise, and it is wrong.** It documents `getGroupsLite` at
  `v4_0_440` and `findGroupsLite` at `v4_0_330`. Sort all 65 paths by `operationId` and they run
  `v4_0_010`, `v4_0_030`, `v4_0_040` … `v4_0_660` — strict alphabetical order, stepping by ten. Those are
  synthetic sequence numbers from the doc generator, not versions; `getGroupsLite` is simply the 44th
  operation alphabetically. IU's own .NET clients set the version once in their base URL and append only
  the resource, which is the correct shape. Keep the version in configuration; it does not belong in
  per-operation constants.
- **Both Lite operations post to the same `/groups` resource**, so the path cannot say which is meant.
  `wsLiteObjectType` is the discriminator — `WsRestGetGroupsLiteRequest` vs
  `WsRestFindGroupsLiteRequest` — which is why the spec declares it required.

Two more worth knowing:

- **`wsLiteObjectType`'s documented *value* is untrustworthy even though the parameter is real.** All 29
  Lite operations declare the identical description `WsRestFindGroupsLiteRequest`, including
  `addMemberLite`, which plainly does not take one — the generator lost the per-operation value. The
  parameter itself is genuine and necessary (it is the operation discriminator), so this library sends the
  value each operation's own request class implies. If a live call is answered by the *wrong* operation,
  suspect these strings.
- **`groupName` cannot be combined with other search parameters** in `findGroupsLite`, per Grouper's own
  parameter documentation. This is why `groupExists()` does not send `stemName` and expects fully
  qualified identifiers.
- **`stemName` is optional, and so is the configured stem.** With no stem, `groupsFor()` omits both
  `stemName` and `stemScope` — they travel together, because Grouper documents stemScope as meaningful
  only alongside a stem. A blank stem normalises to `null` rather than throwing, so an unset
  `GROUPER_STEM` degrades to a broad institution-wide lookup rather than failing at boot. That is a
  deliberate trade and worth knowing when a lookup is mysteriously slow.

**On stem conventions:** the two .NET reference clients hard-code their stems as string literals inside
their JSON bodies — `iu:roles:sys:acm`, `iu:roles:sys:acmex` and `iu:bundles`, chosen per method. They are
shared *institutional* stems, not per-application subtrees, which is why callers of those clients never
pass a stem and may not realise one is being applied. Expect IU deployments to look like that rather than
like an `iu:apps:your-app` subtree. One `GrouperClient` is scoped to one stem; two stems means two
clients, or no stem plus filtering by identifier.

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

## Fixtures

**`tests/fixture/membership-two-groups.json` carries the envelope of a real recorded response** — the
nine-field `WsGroup`, the five-field `wsSubject`, and the real `resultMetadata`/`responseMetadata` shape
from Grouper 4.24.0. **The group identities in it are anonymised**, so the file does not publish one
person's institutional access; keep it that way when re-recording.

Its second group deliberately omits `description`, because 4 of 357 groups in the recorded response had
none. That is the case `GrouperGroup::$description` being nullable exists for.

Verified against IU's production Grouper (server 4.24.0) with a real service account:

- **The request shapes**, both of them — see the landmines above.
- **Stem scoping works and matters.** Unscoped: 357 groups, 5618 ms. `iu:roles:sys`: 187, 814 ms.
  `iu:bundles`: 20, 158 ms.
- **A nonexistent stem is an error**, `400 INVALID_QUERY` / "Stem not found" — not an empty result.
- **A nonexistent subject** answers `404` / `SUBJECT_NOT_FOUND`, which `groupsFor()` deliberately reports
  as an empty membership. It is the one tolerated failure code; see the docblock for why.
- **A nonexistent group** answers `200` / `success="T"` with the `groupResults` key **absent entirely** —
  not an empty array, and not a 404.
- **The version segment is not validated.** A deliberately bogus `v9_9_999` returned all 357 groups.
  `clientVersion` is kept because a future Grouper may start enforcing it, and it costs nothing.

Still unverified:

3. **That a real subject in a real stem with no matching groups returns `success="T"`.** Every live
   lookup tried so far returned at least one group, so the empty case is still inferred rather than
   observed. The inference is strong — `findGroups` returns `success="T"` with the results key absent for
   a group that does not exist — but it is the one remaining assumption the library could still be wrong
   about.

Replace the fixtures with recorded real responses once credentials exist.

## Branching and pull requests

**Do not commit directly to `develop` or `main`.** Work on a feature branch and open a pull request
against `develop`.

Tags live on `main`, never on `develop`:

```
feature branch  --PR-->  develop  --PR-->  main  --> tag (release)
```

Consumers require tagged versions, so **"merged into `develop`" and "released" are two different
states**. A consumer that cannot see your change has almost always hit exactly that.

For a local iteration loop, temporarily add a path repository to the consumer's `composer.json` above its
VCS entries, and revert it before committing:

```json
{ "type": "path", "url": "../grouper", "options": { "symlink": true } }
```

**Never hand-edit `starter/vendor/guild/grouper/`** — the next `composer install` reverts it.
