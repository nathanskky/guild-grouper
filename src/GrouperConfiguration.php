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

    public function __construct(
        string $serviceUrl,
        public string $username,
        public string $password,
        ?string $stem = null,
    ) {
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
    }
}
