# Glossary

> For PHP developers meeting a Grouper term in guild/grouper's docs or code.

| Term | Meaning | In code |
|---|---|---|
| Group | A named set of people in IU Grouper. | `GrouperGroup` |
| Identifier | A group's system name, such as `iu:roles:sys:acm:x-editors`. The only field to match on, because it's unique and stable. | `GrouperGroup::$identifier` |
| ACM | Access Control Management, the tool IU people use to manage group membership. | — |
| ACM label | The short name ACM shows for a group, such as `X Editors`. Not unique, and it can change. | `GrouperGroup::$displayExtension` |
| Stem | A folder in Grouper's group namespace, such as `iu:roles:sys:acm`. Lookups return only groups exactly one level below it. | `GrouperConfiguration::$stem` |
| Membership | The groups one username belongs to under the stem. Empty is a valid answer. | `GroupMembership` |
| Unavailable | Grouper couldn't be reached, so the answer is unknown. | `GrouperUnavailable` |
| Client version | The Grouper API version in every request path. | `GrouperConfiguration::$clientVersion` |

guild/framework also has groups: the Grouper groups an app has registered for its grants. Its docs
define that meaning.
