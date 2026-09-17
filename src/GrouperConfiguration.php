<?php

declare(strict_types=1);

namespace Guild\Grouper;

use Guild\Grouper\Exception\GrouperConfigurationException;

/**
 * Connection settings for the Grouper web services API, validated on
 * construction so a misconfigured deployment fails at boot rather than on the
 * first authorization check.
 */
final readonly class GrouperConfiguration
{
    /**
     * Base URL with any trailing slash removed. Operation paths carry their own
     * version segment (see GrouperClient) and are appended to this directly.
     */
    public string $serviceUrl;

    /**
     * The stem membership queries are scoped to, or null for an unscoped,
     * institution-wide lookup. Blank input is normalised to null: a blank stem
     * is far more often an unset environment variable than a deliberate choice,
     * and sending an empty stemName would be rejected by Grouper anyway.
     */
    public ?string $stem;

    /**
     * The Grouper *client* version -- the version of the API contract this
     * library is coded against, which Grouper uses for backwards compatibility.
     * It is one value for every operation, not one per endpoint.
     *
     * Defaults to the v4 contract this library was built against. It is
     * configurable because Grouper keeps older client versions working, and
     * because a Grouper upgrade may require moving it: the two IU .NET clients
     * in production still run against v2_5_000.
     */
    public string $clientVersion;

    public function __construct(
        string $serviceUrl,
        public string $username,
        public string $password,
        ?string $stem = null,
        string $clientVersion = 'v4_0_000',
    ) {
        if (trim($clientVersion) === '') {
            throw new GrouperConfigurationException('Grouper client version is required; it forms part of every request path.');
        }

        if (trim($serviceUrl) === '') {
            throw new GrouperConfigurationException('Grouper service URL is required.');
        }

        if (! str_starts_with($serviceUrl, 'https://')) {
            throw new GrouperConfigurationException('Grouper service URL must be an https URL; credentials are sent as HTTP Basic auth.');
        }

        if (trim($username) === '' || trim($password) === '') {
            throw new GrouperConfigurationException('Grouper service-account username and password are both required.');
        }

        $this->serviceUrl = rtrim(trim($serviceUrl), '/');
        $this->stem = ($stem === null || trim($stem) === '') ? null : trim($stem);
        $this->clientVersion = trim($clientVersion, " \t\n\r\0\x0B/");
    }
}
