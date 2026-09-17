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
            '/v4_0_440/groups',
            $request->getUri()->getPath(),
            'getGroupsLite carries its version segment in the path',
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
            'https://grouperws.apps.iu.edu/grouper-ws/servicesRest/v4_0_440/groups',
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
