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
     * The stem holding groups managed through ACM (Access Control Management),
     * the tool IU users administer group membership with.
     *
     * This is the default because ACM-managed groups are what an application
     * authorises against: the other institutional stems hold different things
     * (`iu:bundles` compliance bundles, `iu:entlmt:app` entitlements) and are
     * not what an access-control check is asking about.
     *
     * Measured against one real account: 183 of its 357 groups live here, and
     * the namespace is flat -- every one of them sits exactly one level below
     * this stem.
     */
    public const string ACM_STEM = 'iu:roles:sys:acm';

    /**
     * Base URL with any trailing slash removed. Operation paths carry their own
     * version segment (see GrouperClient) and are appended to this directly.
     */
    public string $serviceUrl;

    /**
     * The stem membership queries are scoped to. Defaults to ACM_STEM; an
     * explicit null asks for every group the user belongs to institution-wide.
     *
     * Blank input normalises to null rather than throwing, so an unset
     * GROUPER_STEM degrades to a broad lookup instead of a boot failure. Note
     * that this makes a blank value mean something different from an omitted
     * one, which is deliberate: omitting is the common case and should land on
     * the useful default, while explicitly blanking it is an opt-out.
     */
    public ?string $stem;

    /**
     * The Grouper *client* version -- the version of the API contract a client
     * is coded against, which Grouper uses for backwards compatibility. One
     * value serves every operation; it is not a per-endpoint version.
     *
     * The default, `v2_5_000`, is the version IU's production .NET clients run
     * against, making it the one empirically known to work against IU's
     * deployment. Grouper keeps older client versions working, so moving to a
     * newer contract is a configuration change rather than a code change.
     */
    public string $clientVersion;

    public function __construct(
        string $serviceUrl,
        public string $username,
        public string $password,
        ?string $stem = self::ACM_STEM,
        string $clientVersion = 'v2_5_000',
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
