<?php

declare(strict_types=1);

namespace Guild\Grouper\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Guild\Grouper\Exception\GrouperConfigurationException;
use Guild\Grouper\Exception\GrouperResponseException;
use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperConfiguration;
use Guild\Grouper\GrouperUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

#[CoversClass(GrouperClient::class)]
final class GrouperClientTest extends TestCase
{
    /**
     * Recorded requests from the most recent client built by clientReturning().
     *
     * @var list<array{request: RequestInterface, response: mixed, error: mixed, options: array<string, mixed>}>
     */
    private array $history = [];

    public function test_it_posts_form_encoded_credentials_and_scope(): void
    {
        $this->clientReturning(200, $this->fixture('membership-two-groups'))->groupsFor('jdoe');

        $request = $this->lastRequest();

        self::assertSame('POST', $request->getMethod(), 'every Grouper v4 operation is POST; no GET operation exists');
        self::assertStringEndsWith(
            '/v2_5_000/groups',
            $request->getUri()->getPath(),
            'the path segment is the client version, uniform across operations',
        );
        self::assertSame(
            'application/x-www-form-urlencoded',
            $request->getHeaderLine('Content-Type'),
            'the Lite variants take form-encoded parameters, never a JSON body',
        );
        self::assertSame(
            'Basic '.base64_encode('svc:secret'),
            $request->getHeaderLine('Authorization'),
            'Guzzle\'s auth option emits the Basic header from the service account',
        );
    }

    public function test_it_sends_the_subject_and_the_configured_stem_scope(): void
    {
        $this->clientReturning(200, $this->fixture('membership-two-groups'))->groupsFor('jdoe');

        $body = $this->lastRequestBody();

        self::assertSame('jdoe', $body['subjectIdentifier'] ?? null, 'the username is looked up as a subject identifier');
        self::assertSame('iu:apps:x', $body['stemName'] ?? null, 'queries are scoped to the configured stem');
        self::assertSame(
            'ALL_IN_SUBTREE',
            $body['stemScope'] ?? null,
            'Grouper requires stemScope whenever a stem is passed',
        );
        self::assertArrayNotHasKey('subjectId', $body, 'subjectId and subjectIdentifier are mutually exclusive');
    }

    public function test_it_uses_the_configured_service_url_as_the_base(): void
    {
        $this->clientReturning(200, $this->fixture('membership-two-groups'))->groupsFor('jdoe');

        self::assertSame(
            'https://grouperws.apps.iu.edu/grouper-ws/servicesRest/v2_5_000/groups',
            (string) $this->lastRequest()->getUri(),
        );
    }


    // -- groupsFor: the four outcomes --------------------------------------

    public function test_it_returns_membership_for_a_user_with_groups(): void
    {
        $result = $this->clientReturning(200, $this->fixture('membership-two-groups'))->groupsFor('jdoe');

        self::assertInstanceOf(GroupMembership::class, $result);
        self::assertCount(2, $result->groups);
        self::assertTrue($result->belongsTo('iu:apps:x:editors'));
        self::assertSame('Indiana University:Applications:X:Editors', $result->groups[0]->displayName);
        self::assertSame('People who may edit content in X', $result->groups[0]->description);
        self::assertNull($result->groups[1]->description, 'description is optional on a WsGroup');
    }

    /**
     * The fixture omits wsGroups entirely rather than sending an empty array,
     * which is how Grouper reports "nothing found". It must still be a
     * successful, empty membership.
     */
    public function test_a_user_with_no_groups_returns_an_empty_membership_not_a_failure(): void
    {
        $result = $this->clientReturning(200, $this->fixture('membership-no-groups'))->groupsFor('nobody');

        self::assertInstanceOf(GroupMembership::class, $result);
        self::assertSame([], $result->groups);
        self::assertSame('nobody', $result->username);
    }

    public function test_a_server_error_returns_grouper_unavailable(): void
    {
        $result = $this->clientReturning(503, '')->groupsFor('jdoe');

        self::assertInstanceOf(GrouperUnavailable::class, $result);
        self::assertSame(503, $result->statusCode);
        self::assertNotNull($result->previous, 'the transport error is kept for logging');
    }

    public function test_rate_limiting_returns_grouper_unavailable(): void
    {
        $result = $this->clientReturning(429, '')->groupsFor('jdoe');

        self::assertInstanceOf(GrouperUnavailable::class, $result);
        self::assertSame(429, $result->statusCode, 'a throttled request is a transient condition, not a broken one');
    }

    public function test_a_connection_failure_returns_grouper_unavailable_with_no_status(): void
    {
        $result = $this->clientThrowing(
            new ConnectException('cURL error 28: timed out', new Request('POST', 'https://grouperws.apps.iu.edu'))
        )->groupsFor('jdoe');

        self::assertInstanceOf(GrouperUnavailable::class, $result);
        self::assertNull($result->statusCode, 'a connection that never landed has no HTTP status');
        self::assertInstanceOf(ConnectException::class, $result->previous);
    }

    /**
     * Rejected credentials are a deployment error a human must fix, so they are
     * thrown loudly rather than degraded into a transient "unavailable".
     */
    #[DataProvider('rejectedCredentialStatuses')]
    public function test_rejected_credentials_throw_rather_than_returning_unavailable(int $status): void
    {
        $this->expectException(GrouperConfigurationException::class);

        $this->clientReturning($status, '')->groupsFor('jdoe');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rejectedCredentialStatuses(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }

    public function test_a_malformed_response_throws(): void
    {
        $this->expectException(GrouperResponseException::class);

        $this->clientReturning(200, '{"not":"a grouper envelope"}')->groupsFor('jdoe');
    }

    public function test_a_non_json_response_throws(): void
    {
        $this->expectException(GrouperResponseException::class);

        $this->clientReturning(200, '<html>proxy error</html>')->groupsFor('jdoe');
    }

    /**
     * success="F" is Grouper telling us the request was wrong on its merits.
     * Degrading that to "no groups" would turn a broken integration into a
     * silent authorization failure, so it throws — and carries the only two
     * diagnostics Grouper offers.
     */
    public function test_an_unsuccessful_result_throws_with_grouper_diagnostics(): void
    {
        $body = json_encode([
            'WsGetGroupsLiteResult' => [
                'resultMetadata' => [
                    'success' => 'F',
                    'resultCode' => 'SUBJECT_NOT_FOUND',
                    'resultMessage' => 'Cant find subject',
                ],
            ],
        ]);
        self::assertIsString($body);

        $this->expectException(GrouperResponseException::class);
        $this->expectExceptionMessageMatches('/SUBJECT_NOT_FOUND/');

        $this->clientReturning(200, $body)->groupsFor('ghost');
    }

    /**
     * With no stem configured the client must omit stemName *and* stemScope.
     * Grouper documents stemScope as meaningful only alongside a stem, and an
     * empty stemName would be rejected outright.
     */
    public function test_an_unscoped_lookup_omits_the_stem_parameters(): void
    {
        $this->unscopedClientReturning(200, $this->fixture('membership-two-groups'))->groupsFor('jdoe');

        $body = $this->lastRequestBody();

        self::assertSame('jdoe', $body['subjectIdentifier'] ?? null);
        self::assertArrayNotHasKey('stemName', $body, 'no stem configured means no stemName sent');
        self::assertArrayNotHasKey('stemScope', $body, 'stemScope is meaningless without a stem');
    }

    public function test_an_unscoped_lookup_still_returns_membership(): void
    {
        $result = $this->unscopedClientReturning(200, $this->fixture('membership-two-groups'))->groupsFor('jdoe');

        self::assertInstanceOf(GroupMembership::class, $result);
        self::assertCount(2, $result->groups);
    }

    // -- groupExists -------------------------------------------------------

    public function test_group_exists_queries_find_groups_by_exact_name(): void
    {
        $this->clientReturning(200, $this->fixture('group-found'))->groupExists('iu:apps:x:editors');

        $request = $this->lastRequest();
        $body = $this->lastRequestBody();

        self::assertStringEndsWith(
            '/v2_5_000/groups',
            $request->getUri()->getPath(),
            'both operations post to the same resource under the same client version',
        );
        self::assertSame('FIND_BY_GROUP_NAME_EXACT', $body['queryFilterType'] ?? null);
        self::assertSame('iu:apps:x:editors', $body['groupName'] ?? null);
        self::assertArrayNotHasKey(
            'stemName',
            $body,
            'Grouper documents groupName as unusable alongside other search params',
        );
    }

    public function test_group_exists_is_true_when_grouper_returns_a_match(): void
    {
        $result = $this->clientReturning(200, $this->fixture('group-found'))->groupExists('iu:apps:x:editors');

        self::assertTrue($result);
    }

    public function test_group_exists_is_false_for_an_empty_result(): void
    {
        $result = $this->clientReturning(200, $this->fixture('group-not-found'))->groupExists('iu:apps:x:typo');

        self::assertFalse($result, 'a typo\'d group identifier must be reported, not silently matched by nobody');
    }

    /**
     * Unlike getGroupsLite, findGroupsLite declares an explicit 404 schema, so
     * a 404 here is a meaningful "no such group" rather than a broken route.
     */
    public function test_group_exists_treats_a_404_as_absent_rather_than_a_failure(): void
    {
        $result = $this->clientReturning(404, '{"WsFindGroupsResults":{"resultMetadata":{"success":"F"}}}')
            ->groupExists('iu:apps:x:typo');

        self::assertFalse($result);
    }

    public function test_group_exists_returns_unavailable_on_a_server_error(): void
    {
        $result = $this->clientReturning(500, '')->groupExists('iu:apps:x:editors');

        self::assertInstanceOf(GrouperUnavailable::class, $result);
        self::assertSame(500, $result->statusCode);
    }

    public function test_group_exists_throws_on_rejected_credentials(): void
    {
        $this->expectException(GrouperConfigurationException::class);

        $this->clientReturning(401, '')->groupExists('iu:apps:x:editors');
    }

    /**
     * Both Lite operations post to the same /groups resource under the same
     * client version, so the path cannot tell Grouper which one is meant.
     * wsLiteObjectType is the discriminator, and it is why the parameter is
     * declared required.
     */
    public function test_it_names_the_lite_operation_in_the_request_body(): void
    {
        $this->clientReturning(200, $this->fixture('membership-two-groups'))->groupsFor('jdoe');

        self::assertSame(
            'WsRestGetGroupsLiteRequest',
            $this->lastRequestBody()['wsLiteObjectType'] ?? null,
        );
    }

    public function test_group_exists_names_its_own_lite_operation(): void
    {
        $this->clientReturning(200, $this->fixture('group-found'))->groupExists('iu:apps:x:editors');

        self::assertSame(
            'WsRestFindGroupsLiteRequest',
            $this->lastRequestBody()['wsLiteObjectType'] ?? null,
            'the discriminator is what separates findGroupsLite from getGroupsLite',
        );
    }

    /**
     * The client version is a deployment concern: Grouper keeps older client
     * versions working, so moving to a newer contract must not require a code
     * change.
     */
    public function test_the_client_version_is_configurable(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], $this->fixture('membership-two-groups')),
        ]));
        $stack->push(Middleware::history($this->history));

        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'secret',
            stem: 'iu:apps:x',
            clientVersion: 'v4_0_000',
        );

        (new GrouperClient($config, new Client(['handler' => $stack])))->groupsFor('jdoe');

        self::assertStringEndsWith('/v4_0_000/groups', $this->lastRequest()->getUri()->getPath());
    }

    // -- helpers -----------------------------------------------------------

    private function validConfig(): GrouperConfiguration
    {
        return new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'secret',
            stem: 'iu:apps:x',
        );
    }

    private function clientReturning(int $status, string $body): GrouperClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new GrouperClient($this->validConfig(), new Client(['handler' => $stack]));
    }

    private function unscopedClientReturning(int $status, string $body): GrouperClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));
        $stack->push(Middleware::history($this->history));

        $config = new GrouperConfiguration(
            serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
            username: 'svc',
            password: 'secret',
        );

        return new GrouperClient($config, new Client(['handler' => $stack]));
    }

    private function clientThrowing(\Throwable $error): GrouperClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([$error]));
        $stack->push(Middleware::history($this->history));

        return new GrouperClient($this->validConfig(), new Client(['handler' => $stack]));
    }

    private function lastRequest(): RequestInterface
    {
        self::assertNotEmpty($this->history, 'expected the client to have issued a request');

        return $this->history[array_key_last($this->history)]['request'];
    }

    /**
     * @return array<string, string>
     */
    private function lastRequestBody(): array
    {
        parse_str((string) $this->lastRequest()->getBody(), $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/fixture/'.$name.'.json');
        self::assertIsString($contents, "fixture {$name}.json is readable");

        return $contents;
    }
}
