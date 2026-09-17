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
- **The version segment lives in the path, per operation, and the two operations used here are on
  different versions** — `getGroupsLite` is `v4_0_440`, `findGroupsLite` is `v4_0_330`. These move
  between Grouper releases, which is why they are `private const` at the top of `GrouperClient` and
  nowhere else. When a Grouper upgrade breaks this library, that is the first place to look.

Two more worth knowing:

- **`wsLiteObjectType` is declared `required: true`** on both operations in the published Swagger, and
  this library does **not** send it. That is a considered bet, not an oversight: all 29 Lite operations
  declare the identical description `WsRestFindGroupsLiteRequest` — including `addMemberLite`, which
  plainly does not take one — so the parameter is a generator artifact and its documented value is
  untrustworthy. If live calls fail with a request-shape error, this is the first thing to try.
- **`groupName` cannot be combined with other search parameters** in `findGroupsLite`, per Grouper's own
  parameter documentation. This is why `groupExists()` does not send `stemName` and expects fully
  qualified identifiers.

## Reference material

The authority is the official Grouper WS v4 Swagger specification, which is public and needs no
authentication:

```
https://grouperws.apps.iu.edu/grouper-ws/docs/index.json
```

Re-fetch it rather than trusting a restatement, including this one.

**Do not port the .NET clients** (`SP3.Grouper.Api`, `EA.Grouper.Api.Client`). They target `v2_5_000` and
issue `GET` with a JSON body — an older major version and a request shape that appears nowhere in the v4
specification. They are useful only as an example of flattening Grouper's envelopes into small DTOs.

## Fixtures are synthetic

**`tests/fixture/*.json` are hand-written from the published Swagger definitions, not recorded from a
live Grouper.** They prove the library parses the documented shape. They cannot prove Grouper actually
emits that shape.

Until the package has been exercised against a real service account, three things are unverified:

1. That the base URL and both version segments are what IU actually exposes.
2. That a user with genuinely no groups returns `success="T"` with an absent or empty `wsGroups`, rather
   than an error envelope. **If Grouper signals "no results" as `success="F"`, this library's rules are
   wrong** and the empty case must be reclassified — it would currently throw where it should return an
   empty membership.
3. Whether `wsLiteObjectType` must in fact be sent.

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
