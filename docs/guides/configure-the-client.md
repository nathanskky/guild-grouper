# Configure the client

> For PHP developers connecting an app to IU Grouper.

This guide shows you how to create a `GrouperClient`, set its timeout, look up a user's groups and
choose a stem.

## Before you start

- guild/grouper is installed; the [README](../../README.md#install) shows how.
- You have a Grouper service account, a username and password, allowed to read the stem you'll use.

## Create the client

1. Create a `GrouperConfiguration` with your service account:

   ```php
   use Guild\Grouper\GrouperConfiguration;

   $config = new GrouperConfiguration(
       serviceUrl: $_ENV['GROUPER_URL'],
       username: $_ENV['GROUPER_USER'],
       password: $_ENV['GROUPER_PASSWORD'],
   );
   ```

   The service URL is the REST base without a version segment, such as
   `https://grouperws.apps.iu.edu/grouper-ws/servicesRest`. It must use `https`, because the credentials
   travel as HTTP Basic auth. An invalid value throws `GrouperConfigurationException` here.

2. Create the client with a Guzzle client that has a timeout:

   ```php
   use Guild\Grouper\GrouperClient;
   use GuzzleHttp\Client;

   $grouper = new GrouperClient($config, new Client(['timeout' => 30]));
   ```

Set a timeout you're willing to have each lookup wait for. Guzzle has no timeout by default, so an
unresponsive Grouper looks like a hung request. A lookup scoped to a stem usually takes under a second.
An unscoped lookup for an account with several hundred groups took over five seconds.

## Look up a user's groups

To check whether a user is in a group, call `groupsFor()` and handle `GrouperUnavailable` first:

```php
use Guild\Grouper\GrouperUnavailable;

$membership = $grouper->groupsFor('jdoe');

if ($membership instanceof GrouperUnavailable) {
    $logger->warning('Grouper unavailable', ['reason' => $membership->reason]);

    return false;
}

return $membership->belongsTo('iu:roles:sys:acm:x-editors');
```

`belongsTo()` matches the group's identifier, never its ACM label. `GrouperClient`'s docblocks describe
`groupExists()` and `findByLabel()`, which check a group an administrator enters.

## Choose a stem

Leave `stem` out to use the default, `GrouperConfiguration::ACM_STEM` (`iu:roles:sys:acm`). That stem
holds the groups people manage in ACM, which are the ones an access check asks about.

To use another stem, pass it as `stem:`. Lookups return only groups exactly one level below the stem. A
stem whose groups sit deeper returns an empty membership for everyone, with no error. A stem that doesn't
exist throws `GrouperResponseException`.

To look up every group a person belongs to across IU, pass `stem: null`. A blank string also means no
stem, so `stem: $_ENV['GROUPER_STEM'] ?? ''` with the variable unset gives the slow unscoped lookup, not
the default.

One client serves one stem. To query two stems, create two clients.

## Client version

Leave `clientVersion` at its default, `v2_5_000`. It's the Grouper API version IU's own clients use. IU's
Grouper doesn't currently check the value.

## Related

- [Outcomes](../concepts/outcomes.md) explains what each lookup returns and throws.
- [Glossary](../reference/glossary.md) defines stem and ACM.
