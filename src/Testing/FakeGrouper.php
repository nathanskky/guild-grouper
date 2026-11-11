<?php

declare(strict_types=1);

namespace Guild\Grouper\Testing;

use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperConfiguration;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A real GrouperClient over Guzzle's MockHandler, recording every request it
 * sends, so tests can assert how often Grouper was actually asked.
 *
 * Test support for applications and sibling packages. It ships in autoload
 * so their tests can share one copy, and it is inert unless test code
 * constructs it.
 */
final class FakeGrouper
{
    /**
     * Every request sent, in order.
     *
     * @var list<RequestInterface>
     */
    public private(set) array $requests = [];

    public readonly GrouperClient $client;

    /**
     * @param  list<ResponseInterface|Throwable>  $responses  Returned in order, one per request.
     */
    public function __construct(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->requests[] = $request;

            return $request;
        }));

        $this->client = new GrouperClient(
            new GrouperConfiguration(
                serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest',
                username: 'svc',
                password: 'secret',
            ),
            new Client(['handler' => $stack]),
        );
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * A successful getGroupsLite response.
     *
     * @param  array<string, string>  $groups  identifier => displayExtension
     */
    public static function membership(array $groups): ResponseInterface
    {
        $wsGroups = [];

        foreach ($groups as $identifier => $label) {
            $wsGroups[] = [
                'name' => $identifier,
                'displayName' => 'Indiana University:Roles:' . $label,
                'displayExtension' => $label,
                'uuid' => md5($identifier),
            ];
        }

        $result = ['resultMetadata' => ['resultCode' => 'SUCCESS', 'success' => 'T']];

        if ($wsGroups !== []) {
            $result['wsGroups'] = $wsGroups;
        }

        return new Response(200, [], json_encode(['WsGetGroupsLiteResult' => $result], JSON_THROW_ON_ERROR));
    }

    /**
     * A successful findGroups response, as a label search returns.
     *
     * @param  array<string, string>  $groups  identifier => displayExtension
     */
    public static function labelMatches(array $groups): ResponseInterface
    {
        $results = [];

        foreach ($groups as $identifier => $label) {
            $results[] = [
                'name' => $identifier,
                'displayName' => 'Indiana University:Roles:' . $label,
                'displayExtension' => $label,
                'uuid' => md5($identifier),
            ];
        }

        $result = ['resultMetadata' => ['resultCode' => 'SUCCESS', 'success' => 'T']];

        if ($results !== []) {
            $result['groupResults'] = $results;
        }

        return new Response(200, [], json_encode(['WsFindGroupsResults' => $result], JSON_THROW_ON_ERROR));
    }

    /**
     * The getGroupsLite answer for a username Grouper has never heard of,
     * which the client reads as an empty membership.
     */
    public static function subjectNotFound(): ResponseInterface
    {
        return new Response(404, [], json_encode(['WsGetGroupsLiteResult' => [
            'resultMetadata' => ['resultCode' => 'SUBJECT_NOT_FOUND', 'success' => 'F'],
        ]], JSON_THROW_ON_ERROR));
    }

    public static function unavailable(): ResponseInterface
    {
        return new Response(503, [], 'Service Unavailable');
    }

    public static function rejectedCredentials(): ResponseInterface
    {
        return new Response(401, [], 'Unauthorized');
    }
}
