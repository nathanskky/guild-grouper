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

    public function __construct(
        string $serviceUrl,
        public string $username,
        public string $password,
        public string $stem,
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

        if (trim($stem) === '') {
            throw new GrouperConfigurationException('Grouper stem is required; queries are stem-scoped.');
        }

        $this->serviceUrl = rtrim(trim($serviceUrl), '/');
    }
}
