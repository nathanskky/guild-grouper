# AGENTS.md: guild/grouper

> For maintainers of guild/grouper and their agents. App developers start at [docs/index.md](docs/index.md).

The [workspace handbook](../AGENTS.md) covers cross-repo workflow and conventions.

guild/grouper is a read-only client for the IU Grouper web services API. guild/framework's authorization
is its only Guild consumer. [Decision 0001](docs/decisions/0001-read-only-lookup.md) explains why it
doesn't write, cache or set outage policy; don't add those here.

## Verify

`composer check` must pass. It passes on a clean checkout, so any failure is yours.

## Keeping docs current

Every code change gets a docs check in the same commit. Check README, `docs/`, AGENTS.md, the docblocks
of public classes and the comments at the changed code for anything the change makes wrong or incomplete:

- Update a page that describes the changed behavior.
- If no page covers new public behavior, add one to `docs/` and list it in `docs/index.md`.
- If a maintainer needs a fact the code doesn't show, add a comment where the code is.
- If the behavior is gone, remove its page and its index entry.
- If the change adds or renames a domain term, update `docs/reference/glossary.md`.

Then check the pages that link to a changed page for text that's now wrong, and link a new page from
related pages. A non-obvious choice gets a decision record in `docs/decisions/`.

Public behavior here includes the configuration rules, how each response maps to a result
([Outcomes](docs/concepts/outcomes.md)), and the `FakeGrouper` helpers. The public classes keep their API
reference in docblocks, so a change to them updates the docblock.

## Conventions

- Keep the layout flat apart from `Exception/` and `Testing/`.
- Return a condition that can clear on its own as `GrouperUnavailable`; throw one a person must fix.
- Keep the `Grouper` prefix on any class whose plain name could collide with a consumer's, such as `Group`.
- `Testing/` ships in autoload for consumers' tests; production code never uses it.

The lookups return a union instead of a result object because PHPStan at level `max` then rejects
reading `->groups` before the caller handles `GrouperUnavailable`. Lowering the level loses that check.

## Tests

- Fake HTTP with Guzzle's `MockHandler` and `Middleware::history()`, never PHPUnit mocks.
- Give every test class `#[CoversClass]`; PHPUnit fails a class without it.
- Fixtures in `tests/fixture/` are real responses from IU's production Grouper with identities anonymized.

When you re-record a fixture, keep the shape real and anonymize every group and person: a raw response
describes one person's access, and this repository is public. Keep absent result keys absent rather than
`[]`, because that's what Grouper sends.

## Gotchas

- Grouper routes on path segments; each method posts to its own path.
- `groupsFor()` is form-encoded and the two `groups` lookups send JSON; both are correct.
- `findByLabel()` must not send `stemNameScope`, so it filters deeper matches itself.
- Read success from `resultMetadata.success`, never from the HTTP status.
- Grouper reports "nothing found" by omitting the result key.
- `wsLiteObjectType` is required, and the Swagger's value for it is wrong.
- `groupExists()` can't send a stem, because Grouper rejects `groupName` with other filters.
- A blank stem means no stem, not the default.

`GrouperClient`'s class docblock and the comment at each gotcha's code explain the reason.

## Verifying against Grouper

The authority is Grouper's public v4 Swagger at `https://grouperws.apps.iu.edu/grouper-ws/docs/index.json`.
Its per-operation version numbers are generator sequence numbers, not API versions. Don't port the IU .NET
clients' transport: they send `GET` with a JSON body, and the v4 API is POST-only.

Every response shape the code handles was checked against IU's production Grouper, server 4.24.0, except
Grouper being down: the `GrouperUnavailable` paths are tested only with `MockHandler`. After a Grouper
upgrade, re-check with a standalone script that calls this package directly, not through an app or
Docker. Keep the script's credentials in `.env.grouper.local`, and confirm
`git check-ignore -v .env.grouper.local` matches before you add a password.
