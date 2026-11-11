<?php

declare(strict_types=1);

namespace Guild\Grouper\Test\Testing;

use Guild\Grouper\Exception\GrouperConfigurationException;
use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperConfiguration;
use Guild\Grouper\GrouperGroup;
use Guild\Grouper\GrouperUnavailable;
use Guild\Grouper\Testing\FakeGrouper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeGrouper::class)]
#[UsesClass(GrouperClient::class)]
#[UsesClass(GrouperConfiguration::class)]
#[UsesClass(GroupMembership::class)]
#[UsesClass(GrouperGroup::class)]
#[UsesClass(GrouperUnavailable::class)]
final class FakeGrouperTest extends TestCase
{
    public function test_a_membership_response_is_read_by_the_real_client_as_those_groups(): void
    {
        $result = new FakeGrouper([FakeGrouper::membership(['iu:x' => 'X'])])->client->groupsFor('jdoe');

        self::assertInstanceOf(GroupMembership::class, $result, 'a membership response is a successful answer');
        self::assertCount(1, $result->groups, 'one group was given');
        self::assertSame('iu:x', $result->groups[0]->identifier, 'the identifier is the group\'s system name');
        self::assertSame('X', $result->groups[0]->displayExtension, 'the label is the displayExtension');
    }

    public function test_an_empty_membership_is_a_successful_answer_with_no_groups(): void
    {
        $result = new FakeGrouper([FakeGrouper::membership([])])->client->groupsFor('jdoe');

        self::assertInstanceOf(GroupMembership::class, $result, 'no groups is still an answer');
        self::assertSame([], $result->groups, 'the user belongs to nothing');
    }

    public function test_an_unknown_subject_is_an_empty_membership(): void
    {
        $result = new FakeGrouper([FakeGrouper::subjectNotFound()])->client->groupsFor('ghost');

        self::assertInstanceOf(GroupMembership::class, $result, 'an unknown subject is not an outage');
        self::assertSame([], $result->groups, 'an unknown subject belongs to nothing');
    }

    public function test_an_outage_is_returned_as_unavailable(): void
    {
        $result = new FakeGrouper([FakeGrouper::unavailable()])->client->groupsFor('jdoe');

        self::assertInstanceOf(GrouperUnavailable::class, $result, 'a 503 means the answer is unknown');
        self::assertSame(503, $result->statusCode, 'the status is kept for logging');
    }

    public function test_rejected_credentials_are_thrown(): void
    {
        $this->expectException(GrouperConfigurationException::class);

        new FakeGrouper([FakeGrouper::rejectedCredentials()])->client->groupsFor('jdoe');
    }

    public function test_label_matches_are_read_by_find_by_label(): void
    {
        $result = new FakeGrouper([FakeGrouper::labelMatches(['iu:roles:sys:acm:x' => 'X'])])->client->findByLabel('X');

        self::assertIsArray($result, 'a label search that reaches Grouper returns its matches');
        self::assertCount(1, $result, 'one match was given');
        self::assertSame('iu:roles:sys:acm:x', $result[0]->identifier, 'the match carries its system name');
    }

    public function test_every_request_is_recorded(): void
    {
        $grouper = new FakeGrouper([FakeGrouper::membership([]), FakeGrouper::membership([])]);

        $grouper->client->groupsFor('jdoe');
        $grouper->client->groupsFor('asmith');

        self::assertSame(2, $grouper->requestCount(), 'tests can count how often Grouper was asked');
    }
}
