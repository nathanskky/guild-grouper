# guild/grouper docs

> For PHP developers looking up IU Grouper group membership.

The [glossary](reference/glossary.md) defines group, stem, ACM label and the other terms these pages use.

In a Guild app, the framework's authorization calls guild/grouper for you; follow
[guild/framework's docs](https://github.com/nathanskky/guild-framework) to set it up.

## Guides

- [Configure the client](guides/configure-the-client.md) — read when connecting to Grouper, choosing a stem or setting a timeout.
- [Test with FakeGrouper](guides/test-with-fake-grouper.md) — read when writing a test for code that calls Grouper.

## Concepts

- [Outcomes](concepts/outcomes.md) — read when deciding how your code handles each answer, including an unreachable Grouper and an unknown user.

## Reference

- [Glossary](reference/glossary.md) — read when a Grouper term is new to you.

`GrouperClient`, `GrouperConfiguration` and the result classes document their methods and arguments in
docblocks, which your IDE shows on hover.
