# Test with FakeGrouper

> For PHP developers testing code that calls guild/grouper.

This guide shows you how to give your code a Grouper client that answers from canned responses.

## Before you start

Your code must receive its `GrouperClient` instead of building one, so a test can pass it the fake.

## Fake Grouper's answers

1. Create a `FakeGrouper` with the responses Grouper sends, one per request, in order:

   ```php
   use Guild\Grouper\Testing\FakeGrouper;

   $grouper = new FakeGrouper([
       FakeGrouper::membership(['iu:roles:sys:acm:x-editors' => 'X Editors']),
   ]);
   ```

2. Pass `$grouper->client` to the code under test.
3. To check how often Grouper was asked, assert on `$grouper->requestCount()`, or read the requests in
   `$grouper->requests`.

The fake's client uses the default stem, `iu:roles:sys:acm`.

## Response helpers

| Helper | What the client returns or throws |
|---|---|
| `membership([identifier => label, …])` | `groupsFor()` returns those groups |
| `labelMatches([identifier => label, …])` | `findByLabel()` returns them, and `groupExists()` returns `true` |
| `subjectNotFound()` | `groupsFor()` returns an empty membership |
| `unavailable()` | Any lookup returns `GrouperUnavailable` with status 503 |
| `rejectedCredentials()` | Any lookup throws `GrouperConfigurationException` |

`findByLabel()` keeps only groups exactly one level below the stem, so give `labelMatches()` identifiers
such as `iu:roles:sys:acm:x-editors`. To simulate a dropped connection, pass a Guzzle `ConnectException`
in the response list.

## Related

- [Outcomes](../concepts/outcomes.md) explains each result the helpers produce.
