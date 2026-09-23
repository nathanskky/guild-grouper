<?php

declare(strict_types=1);

namespace Guild\Grouper;

use Guild\Grouper\Exception\GrouperConfigurationException;
use Guild\Grouper\Exception\GrouperResponseException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Read-only client for the IU Grouper web services API.
 *
 * This looks up group membership and nothing else. There is no create, update,
 * delete, add-member or remove-member here, by design — group management is
 * done in Grouper itself, not through applications.
 *
 * Five things about Grouper's API are easy to get wrong, and all five are
 * handled in one place here:
 *
 *  1. **Everything here is POST**, and the published v4 specification declares
 *     no GET operation at all. (Live Grouper does answer GET for some of these,
 *     but nothing in this library relies on that.)
 *  2. **Success is signalled by `resultMetadata.success`**, a string "T"/"F" —
 *     not by the HTTP status alone. A 200 can carry a failure.
 *  3. **The version segment in the path is the client version** — the API
 *     contract a client is coded against. One value serves every operation, and
 *     it comes from GrouperConfiguration::$clientVersion.
 *  4. **Grouper routes on URL path segments**, so each operation is addressed
 *     rather than named in the body. `groupsFor()` posts to
 *     `subjects/{subject}/groups`; `groupExists()` posts to `groups`. Posting
 *     to the wrong one is answered with INVALID_QUERY, not a 404.
 *  5. **The two operations use different transports**, which is genuinely how
 *     Grouper works rather than an inconsistency here. `groupsFor()` is the
 *     form-encoded Lite shape and names itself in `wsLiteObjectType`;
 *     `groupExists()` sends a JSON body and names itself in the wrapper key.
 *
 * The published Swagger gives each operation its own version segment —
 * `getGroupsLite` at `v4_0_440`, `findGroupsLite` at `v4_0_330`. These are
 * generator sequence numbers, not versions: sorted by operationId, the 65 paths
 * run `v4_0_010`, `v4_0_030`, `v4_0_040` … `v4_0_660`, alphabetical and
 * stepping by ten. Do not derive a version from them.
 */
final readonly class GrouperClient
{
    /**
     * How deep below the configured stem to look.
     *
     * ONE_LEVEL, matching IU's production .NET clients. ACM's namespace is flat
     * — every ACM group sits exactly one level below `iu:roles:sys:acm` — so
     * this is the precise scope for the default stem.
     *
     * The consequence to know about: against a stem whose groups live *deeper*
     * than one level, ONE_LEVEL returns nothing, successfully. A scoped lookup
     * on `iu:roles:sys` answers `success="T"` with no groups at all, because
     * every group under it is really under `iu:roles:sys:acm`. If a configured
     * stem starts returning empty memberships for everyone, this is why.
     */
    private const string STEM_SCOPE = 'ONE_LEVEL';

    /** getGroupsLite — the groups a subject belongs to. */
    private const string GET_GROUPS_OBJECT_TYPE = 'WsRestGetGroupsLiteRequest';

    /** findGroups — group lookup by name, sent as a JSON body. */
    private const string FIND_GROUPS_REQUEST = 'WsRestFindGroupsRequest';

    public function __construct(
        private GrouperConfiguration $config,
        private ClientInterface $http,
    ) {
    }

    /**
     * The groups $username belongs to within the configured stem.
     *
     * Returns an empty GroupMembership when the user belongs to nothing — that
     * is a successful answer. GrouperUnavailable means the answer is unknown.
     */
    public function groupsFor(string $username): GroupMembership|GrouperUnavailable
    {
        $params = ['wsLiteObjectType' => self::GET_GROUPS_OBJECT_TYPE];

        // stemName is optional in Grouper's own specification. With no stem
        // configured this asks for every group the user belongs to
        // institution-wide -- broad and slow, but legitimate. stemScope is only
        // meaningful alongside a stem, so the two travel together or not at all.
        if ($this->config->stem !== null) {
            $params['stemName'] = $this->config->stem;
            $params['stemScope'] = self::STEM_SCOPE;
        }

        $response = $this->post('subjects/'.rawurlencode($username).'/groups', ['form_params' => $params]);

        if ($response instanceof GrouperUnavailable) {
            return $response;
        }

        $result = $this->envelope((string) $response->getBody(), 'WsGetGroupsLiteResult');

        // Grouper answers 404 / SUBJECT_NOT_FOUND when the username is not a
        // subject at all -- a deprovisioned account, a guest, or a typo. That is
        // an empty membership, not a failure: an authorization check denies on
        // an empty membership, which is the right outcome for an unknown user,
        // whereas throwing would turn a deprovisioned account hitting the app
        // into a 500 rather than a clean denial.
        if ($this->metadataString($result['resultMetadata'] ?? null, 'resultCode') === 'SUBJECT_NOT_FOUND') {
            return new GroupMembership($username, []);
        }

        $this->assertSuccess($result);

        return new GroupMembership($username, $this->groupsFrom($result, 'wsGroups'));
    }

    /**
     * Whether a group with this exact identifier exists in Grouper.
     *
     * Worth calling when an administrator registers a group by hand: a typo'd
     * identifier is not an error anywhere else in this library, it simply
     * matches nobody forever, which looks identical to a correctly configured
     * group that happens to be empty.
     *
     * Returns GrouperUnavailable when the answer is unknown. It is deliberately
     * not folded into `false` — "this group does not exist" and "I could not
     * ask" would otherwise be indistinguishable, and only one of them should
     * make an administrator go fix something.
     */
    public function groupExists(string $identifier): bool|GrouperUnavailable
    {
        // findGroups takes a JSON body rather than the form-encoded Lite shape
        // that groupsFor() uses. The two operations genuinely differ here.
        $response = $this->post('groups', ['json' => [
            self::FIND_GROUPS_REQUEST => [
                'wsQueryFilter' => [
                    'queryFilterType' => 'FIND_BY_GROUP_NAME_EXACT',
                    // Grouper documents groupName as mutually exclusive with the
                    // other search parameters, so the configured stem is not sent
                    // here. Callers pass fully qualified identifiers anyway.
                    'groupName' => $identifier,
                ],
            ],
        ]]);

        if ($response instanceof GrouperUnavailable) {
            return $response;
        }

        $result = $this->envelope((string) $response->getBody(), 'WsFindGroupsResults');
        $this->assertSuccess($result);

        return $this->groupsFrom($result, 'groupResults') !== [];
    }

    /**
     * The groups in the configured stem whose ACM label (displayExtension) is
     * exactly $displayExtension.
     *
     * For registering a group from the label an administrator sees in ACM.
     * Labels are not unique, so this returns every match for a person to
     * choose from; an empty list means no such group. GrouperUnavailable means
     * the answer is unknown, which must not be shown as "no such group".
     *
     * @return list<GrouperGroup>|GrouperUnavailable
     */
    public function findByLabel(string $displayExtension): array|GrouperUnavailable
    {
        $filter = [
            'queryFilterType' => 'FIND_BY_EXACT_ATTRIBUTE',
            'groupAttributeName' => 'displayExtension',
            'groupAttributeValue' => $displayExtension,
        ];

        // Verified against production: this filter accepts stemName but rejects
        // stemNameScope with INVALID_QUERY, and stemName alone searches the
        // whole subtree. groupsFor() only sees groups exactly one level below
        // the stem, so a deeper match is dropped here: registering it would
        // create a mapping that no membership lookup could ever match.
        $stem = $this->config->stem;

        if ($stem !== null) {
            $filter['stemName'] = $stem;
        }

        $response = $this->post('groups', ['json' => [
            self::FIND_GROUPS_REQUEST => ['wsQueryFilter' => $filter],
        ]]);

        if ($response instanceof GrouperUnavailable) {
            return $response;
        }

        $result = $this->envelope((string) $response->getBody(), 'WsFindGroupsResults');
        $this->assertSuccess($result);

        $groups = $this->groupsFrom($result, 'groupResults');

        if ($stem === null) {
            return $groups;
        }

        return array_values(array_filter(
            $groups,
            static fn (GrouperGroup $group): bool => str_starts_with($group->identifier, $stem . ':')
                && ! str_contains(substr($group->identifier, strlen($stem) + 1), ':'),
        ));
    }

    /**
     * Issue one POST and classify the outcome.
     *
     * The split here is the library's central design decision. A condition that
     * may clear on its own is *returned* as GrouperUnavailable so the caller can
     * have a policy about it; a condition a human must fix is *thrown*.
     *
     * A 4xx that is neither a credential rejection nor throttling is returned as
     * the response itself, for the caller to interpret. Grouper puts real
     * meaning in those bodies: a 404 carries SUBJECT_NOT_FOUND, and a 400
     * carries INVALID_QUERY with the reason, both as ordinary result envelopes.
     *
     * @param  string  $resourcePath  Path below the version segment, already URL-encoded.
     * @param  array{form_params?: array<string, string>, json?: array<string, mixed>}  $body
     */
    private function post(string $resourcePath, array $body): ResponseInterface|GrouperUnavailable
    {
        $url = sprintf('%s/%s/%s', $this->config->serviceUrl, $this->config->clientVersion, $resourcePath);

        try {
            return $this->http->request('POST', $url, $body + [
                'auth' => [$this->config->username, $this->config->password],
            ]);
        } catch (ConnectException $e) {
            return new GrouperUnavailable('Could not connect to Grouper.', null, $e);
        } catch (BadResponseException $e) {
            $status = $e->getResponse()->getStatusCode();

            if ($status === 401 || $status === 403) {
                throw new GrouperConfigurationException(
                    "Grouper rejected the service account credentials (HTTP {$status}). Check the configured username and password, and that the account is authorised for this stem.",
                    previous: $e,
                );
            }

            if ($status === 429 || $status >= 500) {
                return new GrouperUnavailable(
                    "Grouper returned HTTP {$status}.",
                    $status,
                    $e,
                );
            }

            return $e->getResponse();
        } catch (GuzzleException $e) {
            return new GrouperUnavailable('Grouper request failed: '.$e->getMessage(), null, $e);
        }
    }

    /**
     * Decode a response and return the inner result, or throw.
     *
     * Success is deliberately not checked here: groupsFor() has to inspect the
     * result code before deciding, because one failure code is tolerated.
     *
     * @return array<string, mixed>
     */
    private function envelope(string $body, string $wrapperKey): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded[$wrapperKey]) || ! is_array($decoded[$wrapperKey])) {
            throw new GrouperResponseException(
                "Grouper response did not contain a {$wrapperKey} envelope.",
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded[$wrapperKey];

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertSuccess(array $result): void
    {
        $metadata = $result['resultMetadata'] ?? null;
        $success = $this->metadataString($metadata, 'success');

        if ($success !== 'T') {
            throw new GrouperResponseException(sprintf(
                'Grouper reported failure (success=%s, resultCode=%s): %s',
                $success ?? 'missing',
                $this->metadataString($metadata, 'resultCode') ?? 'none',
                $this->metadataString($metadata, 'resultMessage') ?? 'no message',
            ));
        }
    }

    /**
     * Grouper serialises every scalar as a string, including its result codes.
     */
    private function metadataString(mixed $metadata, string $key): ?string
    {
        if (! is_array($metadata) || ! isset($metadata[$key]) || ! is_string($metadata[$key])) {
            return null;
        }

        return $metadata[$key];
    }

    /**
     * Flatten a WsGroup list. An absent key and an empty array both mean "no
     * groups", which is a valid answer.
     *
     * @param  array<string, mixed>  $result
     * @return list<GrouperGroup>
     */
    private function groupsFrom(array $result, string $key): array
    {
        $raw = $result[$key] ?? [];

        if (! is_array($raw)) {
            throw new GrouperResponseException("Grouper returned a non-list {$key}.");
        }

        $groups = [];

        foreach ($raw as $group) {
            if (! is_array($group)) {
                throw new GrouperResponseException("Grouper returned a malformed entry in {$key}.");
            }

            $name = $this->metadataString($group, 'name');

            if ($name === null) {
                throw new GrouperResponseException("Grouper returned a group in {$key} with no name.");
            }

            $displayName = $this->metadataString($group, 'displayName') ?? $name;

            $groups[] = new GrouperGroup(
                identifier: $name,
                displayName: $displayName,
                displayExtension: $this->metadataString($group, 'displayExtension')
                    ?? $this->lastSegment($displayName),
                uuid: $this->metadataString($group, 'uuid') ?? '',
                description: $this->metadataString($group, 'description'),
            );
        }

        return $groups;
    }

    /**
     * The final segment of a colon-delimited display path, which is what
     * Grouper's displayExtension holds when it is present.
     */
    private function lastSegment(string $path): string
    {
        $position = strrpos($path, ':');

        return $position === false ? $path : substr($path, $position + 1);
    }
}
