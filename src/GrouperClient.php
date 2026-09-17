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
 * Three things about Grouper's API are easy to get wrong and are all handled in
 * one place here:
 *
 *  1. **Every operation is POST.** There is no GET anywhere in the v4 API, and
 *     the Lite variants take form-encoded parameters, never a JSON body.
 *  2. **Success is signalled by `resultMetadata.success`**, a string "T"/"F" —
 *     not by the HTTP status alone.
 *  3. **The version segment lives in the path, per operation**, and the two
 *     operations used here are on *different* versions. These segments move
 *     between Grouper releases, so they are declared as constants below and
 *     nowhere else.
 */
final readonly class GrouperClient
{
    /**
     * getGroupsLite — the groups a subject belongs to.
     */
    private const string GET_GROUPS_PATH = '/v4_0_440/groups';

    /**
     * findGroupsLite — group lookup. Note this is a *different* version segment
     * than getGroupsLite above; Grouper versions each operation independently.
     */
    private const string FIND_GROUPS_PATH = '/v4_0_330/groups';

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
        $params = ['subjectIdentifier' => $username];

        // stemName is optional in Grouper's own specification. With no stem
        // configured this asks for every group the user belongs to
        // institution-wide -- broad and slow, but legitimate. stemScope is only
        // meaningful alongside a stem, so the two travel together or not at all.
        if ($this->config->stem !== null) {
            $params['stemName'] = $this->config->stem;
            $params['stemScope'] = 'ALL_IN_SUBTREE';
        }

        $response = $this->post(self::GET_GROUPS_PATH, $params);

        if ($response instanceof GrouperUnavailable) {
            return $response;
        }

        $result = $this->resultOrFail((string) $response->getBody(), 'WsGetGroupsLiteResult');

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
        $response = $this->post(self::FIND_GROUPS_PATH, [
            'queryFilterType' => 'FIND_BY_GROUP_NAME_EXACT',
            // Grouper documents groupName as mutually exclusive with the other
            // search parameters, so the configured stem is deliberately not
            // sent here. Callers pass fully qualified identifiers anyway.
            'groupName' => $identifier,
        ]);

        if ($response instanceof GrouperUnavailable) {
            return $response;
        }

        // findGroupsLite declares an explicit 404 schema, unlike getGroupsLite.
        // Here that status is a meaningful "no such group", not a broken route.
        if ($response->getStatusCode() === 404) {
            return false;
        }

        $result = $this->resultOrFail((string) $response->getBody(), 'WsFindGroupsResults');

        return $this->groupsFrom($result, 'groupResults') !== [];
    }

    /**
     * Issue one form-encoded POST and classify the outcome.
     *
     * The split here is the library's central design decision. A condition that
     * may clear on its own is *returned* as GrouperUnavailable so the caller can
     * have a policy about it; a condition a human must fix is *thrown*.
     *
     * A 4xx that is neither a credential rejection nor throttling is returned as
     * the response itself, because its meaning is operation-specific: a 404 from
     * findGroupsLite means "no such group", while the same status from
     * getGroupsLite means the integration is pointed somewhere wrong.
     *
     * @param  array<string, string>  $formParams
     */
    private function post(string $path, array $formParams): ResponseInterface|GrouperUnavailable
    {
        try {
            return $this->http->request('POST', $this->config->serviceUrl.$path, [
                'auth' => [$this->config->username, $this->config->password],
                'form_params' => $formParams,
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
     * Decode an envelope and return the inner result, or throw.
     *
     * @return array<string, mixed>
     */
    private function resultOrFail(string $body, string $wrapperKey): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded[$wrapperKey]) || ! is_array($decoded[$wrapperKey])) {
            throw new GrouperResponseException(
                "Grouper response did not contain a {$wrapperKey} envelope.",
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded[$wrapperKey];

        $metadata = $result['resultMetadata'] ?? null;
        $success = is_array($metadata) && isset($metadata['success']) && is_string($metadata['success'])
            ? $metadata['success']
            : null;

        if ($success !== 'T') {
            throw new GrouperResponseException(sprintf(
                'Grouper reported failure (success=%s, resultCode=%s): %s',
                $success ?? 'missing',
                $this->metadataString($metadata, 'resultCode') ?? 'none',
                $this->metadataString($metadata, 'resultMessage') ?? 'no message',
            ));
        }

        return $result;
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

            $groups[] = new GrouperGroup(
                identifier: $name,
                displayName: $this->metadataString($group, 'displayName') ?? $name,
                uuid: $this->metadataString($group, 'uuid') ?? '',
                description: $this->metadataString($group, 'description'),
            );
        }

        return $groups;
    }
}
