<?php

declare(strict_types=1);

namespace Guild\Grouper\Test;

use Guild\Grouper\Exception\GrouperConfigurationException;
use Guild\Grouper\GrouperConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GrouperConfiguration::class)]
final class GrouperConfigurationTest extends TestCase
{
    public function test_it_rejects_an_empty_service_url(): void
    {
        $this->expectException(GrouperConfigurationException::class);

        new GrouperConfiguration(serviceUrl: '', username: 'svc', password: 'p', stem: 'iu:apps:x');
    }

    public function test_it_rejects_a_non_https_service_url(): void
    {
        $this->expectException(GrouperConfigurationException::class);

        new GrouperConfiguration(serviceUrl: 'http://grouper.iu.edu/ws', username: 'svc', password: 'p', stem: 'iu:apps:x');
    }

    /**
     * Credentials travel as HTTP Basic auth, which is base64, not encryption.
     * A blank half of the pair would ship a malformed header to a production
     * service rather than failing at boot, so both halves are required.
     *
     * @param  non-empty-string  $username
     * @param  non-empty-string  $password
     */
    #[DataProvider('incompleteCredentials')]
    public function test_it_requires_both_halves_of_the_service_account(string $username, string $password): void
    {
        $this->expectException(GrouperConfigurationException::class);

        new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: $username,
            password: $password,
            stem: 'iu:apps:x',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function incompleteCredentials(): iterable
    {
        yield 'no username' => ['', 'p'];
        yield 'no password' => ['svc', ''];
        yield 'whitespace username' => ['   ', 'p'];
        yield 'whitespace password' => ['svc', "\t"];
    }

    /**
     * The stem is optional, matching Grouper itself -- stemName is not a
     * required parameter on getGroupsLite. Omitting it asks for every group the
     * user belongs to institution-wide, which is a broad and slow query, but a
     * legitimate one.
     */
    public function test_it_accepts_a_configuration_with_no_stem(): void
    {
        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'p',
        );

        self::assertNull($config->stem, 'an unscoped lookup is allowed');
    }

    /**
     * A blank stem is almost always an unset environment variable rather than a
     * deliberate institution-wide query. Normalising it to null keeps the
     * client from sending an empty stemName, which Grouper would reject.
     */
    #[DataProvider('blankStems')]
    public function test_it_normalises_a_blank_stem_to_null(string $stem): void
    {
        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'p',
            stem: $stem,
        );

        self::assertNull($config->stem);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankStems(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tab' => ["\t"];
    }

    public function test_it_trims_surrounding_whitespace_from_a_stem(): void
    {
        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'p',
            stem: '  iu:roles:sys:acm  ',
        );

        self::assertSame('iu:roles:sys:acm', $config->stem);
    }

    public function test_it_accepts_a_valid_configuration(): void
    {
        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'p',
            stem: 'iu:apps:x',
        );

        self::assertSame('iu:apps:x', $config->stem, 'the stem is exposed verbatim for use as stemName');
    }

    /**
     * A trailing slash on the base URL would produce a double slash when the
     * per-operation version segment is appended. Normalising here keeps that
     * concern out of the client.
     */
    public function test_it_trims_a_trailing_slash_from_the_service_url(): void
    {
        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest/',
            username: 'svc',
            password: 'p',
            stem: 'iu:apps:x',
        );

        self::assertSame(
            'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            $config->serviceUrl,
            'the trailing slash is removed so operation paths can be appended directly',
        );
    }

    public function test_the_client_version_defaults_to_the_version_iu_is_known_to_accept(): void
    {
        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'p',
        );

        self::assertSame(
            'v2_5_000',
            $config->clientVersion,
            "IU's production .NET clients run against this version",
        );
    }

    public function test_it_rejects_a_blank_client_version(): void
    {
        $this->expectException(GrouperConfigurationException::class);

        new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'p',
            clientVersion: '  ',
        );
    }
}
