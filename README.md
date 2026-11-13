# guild/grouper

Read-only IU Grouper lookups for PHP apps. It tells you which groups an IU username belongs to, whether a
group exists, and which groups carry a given ACM label. It never changes a group or a membership.

In a Guild app, [guild/framework](https://github.com/nathanskky/guild-framework)'s authorization calls
guild/grouper for you.

## Install

guild/grouper requires PHP 8.5 and a Grouper service account.

Add this package's VCS repository to `repositories` in your app's `composer.json`:

```json
{ "type": "vcs", "url": "https://github.com/nathanskky/guild-grouper.git" }
```

Then install it:

```bash
composer require guild/grouper:^0.1
```

guild/grouper is pre-1.0, so a minor release, such as `0.2.0`, can break your code.

## Start here

[Configure the client](docs/guides/configure-the-client.md) shows you how to connect to Grouper and look
up a user's groups.

## All docs

The [docs index](docs/index.md) lists every guide, concept and reference page.
