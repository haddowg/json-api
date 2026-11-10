<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\MembershipChangeProhibited;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Membership;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Membership::class)]
#[CoversClass(MembershipChangeProhibited::class)]
#[CoversClass(AbstractRelationValue::class)]
#[CoversClass(AbstractResource::class)]
final class MembershipGuardTest extends TestCase
{
    #[Test]
    public function aReplaceListingTheCurrentMembersInAnotherOrderIsApplied(): void
    {
        $model = (new MembershipLockedResource())->hydrateRelationship('tags', $this->request('PATCH', [
            'data' => [['type' => 'tags', 'id' => '2'], ['type' => 'tags', 'id' => '1']],
        ]), ['tags' => ['1', '2']]);

        self::assertSame(['tags' => ['2', '1']], $model);
    }

    #[Test]
    public function aReplaceAddingAMemberIsAMembershipChange(): void
    {
        $this->expectProhibited();

        (new MembershipLockedResource())->hydrateRelationship('tags', $this->request('PATCH', [
            'data' => [['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2'], ['type' => 'tags', 'id' => '3']],
        ]), ['tags' => ['1', '2']]);
    }

    #[Test]
    public function aReplaceLeavingOutAMemberIsAMembershipChange(): void
    {
        $this->expectProhibited();

        (new MembershipLockedResource())->hydrateRelationship('tags', $this->request('PATCH', [
            'data' => [['type' => 'tags', 'id' => '1']],
        ]), ['tags' => ['1', '2']]);
    }

    #[Test]
    public function theErrorIsA403AtTheRelationshipPointer(): void
    {
        try {
            (new MembershipLockedResource())->hydrateRelationship('tags', $this->request('PATCH', ['data' => []]), ['tags' => ['1']]);
            self::fail('Expected the membership change to be refused.');
        } catch (MembershipChangeProhibited $exception) {
            $error = $exception->getErrors()[0];
            self::assertSame(403, $exception->getStatusCode());
            self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $error->code);
            self::assertSame('/data/relationships/tags', $error->source?->pointer);
        }
    }

    #[Test]
    public function anAddToTheRelationshipEndpointGetsTheAdditionError(): void
    {
        $this->expectException(AdditionProhibited::class);

        (new MembershipLockedResource())->hydrateRelationship('tags', $this->request('POST', [
            'data' => [['type' => 'tags', 'id' => '3']],
        ]), ['tags' => ['1']]);
    }

    #[Test]
    public function aRemovalFromTheRelationshipEndpointGetsTheRemovalError(): void
    {
        $this->expectException(RemovalProhibited::class);

        (new MembershipLockedResource())->hydrateRelationship('tags', $this->request('DELETE', [
            'data' => [['type' => 'tags', 'id' => '1']],
        ]), ['tags' => ['1']]);
    }

    #[Test]
    public function theClosureLocksTheMembersOnlyForTheRequestsItMatches(): void
    {
        $resource = new MembershipLockedResource();
        $body = ['data' => [['type' => 'tags', 'id' => '9']]];

        self::assertSame(['labels' => ['9']], $resource->hydrateRelationship('labels', $this->request('PATCH', $body, ['X-Role' => 'admin']), ['labels' => ['1']]));
        self::assertSame(['labels' => ['1', '9']], $resource->hydrateRelationship('labels', $this->request('POST', $body, ['X-Role' => 'admin']), ['labels' => ['1']]));

        $this->expectProhibited();
        $resource->hydrateRelationship('labels', $this->request('PATCH', $body), ['labels' => ['1']]);
    }

    #[Test]
    public function theClosureAlsoClosesTheAddGateForTheRequestsItMatches(): void
    {
        $this->expectException(AdditionProhibited::class);

        (new MembershipLockedResource())->hydrateRelationship('labels', $this->request('POST', [
            'data' => [['type' => 'tags', 'id' => '9']],
        ]), ['labels' => ['1']]);
    }

    #[Test]
    public function aWholeResourceUpdateChangingTheMembersIsRefused(): void
    {
        $this->expectProhibited();

        (new MembershipLockedResource())->hydrate($this->request('PATCH', ['data' => [
            'type' => 'locked',
            'id' => '1',
            'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '3']]]],
        ]]), ['id' => '1', 'tags' => ['1', '2']]);
    }

    #[Test]
    public function aWholeResourceUpdateReorderingTheMembersIsApplied(): void
    {
        $model = (new MembershipLockedResource())->hydrate($this->request('PATCH', ['data' => [
            'type' => 'locked',
            'id' => '1',
            'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '2'], ['type' => 'tags', 'id' => '1']]]],
        ]]), ['id' => '1', 'tags' => ['1', '2']]);

        self::assertIsArray($model);
        self::assertSame(['2', '1'], $model['tags']);
    }

    #[Test]
    public function aCreateSetsTheMembersFreely(): void
    {
        $model = (new MembershipLockedResource())->hydrate($this->request('POST', ['data' => [
            'type' => 'locked',
            'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '3']]]],
        ]]), []);

        self::assertIsArray($model);
        self::assertSame(['3'], $model['tags']);
    }

    #[Test]
    public function theCurrentMembersAreReadThroughTheRelatedSerializer(): void
    {
        $resource = new MembershipLockedResource();
        $resource->setSerializerResolver(new MembershipTagResolver());
        $model = ['tags' => [(object) ['id' => '1'], (object) ['id' => '2']]];

        $resource->hydrateRelationship('tags', $this->request('PATCH', [
            'data' => [['type' => 'tags', 'id' => '2'], ['type' => 'tags', 'id' => '1']],
        ]), $model);

        $this->expectProhibited();
        $resource->hydrateRelationship('tags', $this->request('PATCH', [
            'data' => [['type' => 'tags', 'id' => '2']],
        ]), $model);
    }

    #[Test]
    public function theStaticGatesReportALockedRelation(): void
    {
        $relation = HasMany::make('tags', 'tags')->cannotChangeMembership()->build();

        self::assertFalse($relation->allowsMembershipChange());
        self::assertFalse($relation->allowsAdd());
        self::assertFalse($relation->allowsRemove());
        self::assertTrue($relation->allowsReplace());
        self::assertTrue(HasMany::make('tags', 'tags')->build()->allowsMembershipChange());
    }

    #[Test]
    public function aClosureLeavesTheStaticGatesOpen(): void
    {
        $relation = BelongsToMany::make('tags', 'tags')
            ->cannotChangeMembership(static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin')
            ->build();

        self::assertTrue($relation->allowsMembershipChange());
        self::assertTrue($relation->allowsAdd());
        self::assertTrue($relation->allowsRemove());
        self::assertFalse($relation->allowsMembershipChangeFor(new StubJsonApiRequest(), null));
        self::assertFalse($relation->allowsAddFor(new StubJsonApiRequest(), null));
        self::assertTrue($relation->allowsMembershipChangeFor(StubJsonApiRequest::create([], ['X-Role' => 'admin']), null));
    }

    #[Test]
    public function aMorphToManyCanLockItsMembers(): void
    {
        self::assertFalse(MorphToMany::make('owners', ['users', 'teams'])->cannotChangeMembership()->build()->allowsMembershipChange());
    }

    #[Test]
    public function aToOneCannotLockItsMembers(): void
    {
        foreach ([BelongsTo::make('owner', 'users'), HasOne::make('profile', 'profiles'), MorphTo::make('subject', ['users', 'teams'])] as $builder) {
            try {
                $builder->cannotChangeMembership()->build();
                self::fail('Expected the build to fail.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('cannotChangeMembership()', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function membershipIgnoresOrderAndDuplicates(): void
    {
        $incoming = new ToManyRelationship([new ResourceIdentifier('tags', '2'), new ResourceIdentifier('tags', '1'), new ResourceIdentifier('tags', '2')]);

        self::assertFalse(Membership::differs([['tags', 1], new ResourceIdentifier('tags', '2')], $incoming));
        self::assertTrue(Membership::differs([['tags', '1']], $incoming));
        self::assertTrue(Membership::differs([['tags', '1'], ['tags', '2'], ['tags', '3']], $incoming));
    }

    #[Test]
    public function membershipComparesTheTypeToo(): void
    {
        self::assertTrue(Membership::differs([['users', '1']], new ToManyRelationship([new ResourceIdentifier('teams', '1')])));
    }

    #[Test]
    public function aMemberSentWithOnlyALidIsAlwaysAChange(): void
    {
        self::assertTrue(Membership::differs([], new ToManyRelationship([new ResourceIdentifier('tags', lid: 'new-tag')])));
    }

    private function expectProhibited(): void
    {
        $this->expectException(MembershipChangeProhibited::class);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function request(string $method, array $body, array $headers = []): JsonApiRequest
    {
        $psr = (new ServerRequest($method, '/api/locked', $headers))
            ->withParsedBody($body)
            ->withHeader('Content-Type', 'application/vnd.api+json');

        return new JsonApiRequest($psr);
    }

    #[Test]
    public function aRelationReportsWhetherItFixesItsMembers(): void
    {
        self::assertTrue(HasMany::make('tags', 'tags')->cannotChangeMembership()->build()->fixesMembership());
        self::assertTrue(HasMany::make('tags', 'tags')->cannotChangeMembership(static fn(): bool => true)->build()->fixesMembership());
        self::assertFalse(HasMany::make('tags', 'tags')->build()->fixesMembership());
    }
}

final class MembershipLockedResource extends AbstractResource
{
    public static string $type = 'locked';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('tags', 'tags')->cannotChangeMembership(),
            HasMany::make('labels', 'tags')->cannotChangeMembership(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
            ),
        ];
    }
}

final class MembershipTagResource extends AbstractResource
{
    public static string $type = 'tags';

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class MembershipTagResolver implements \haddowg\JsonApi\Resource\SerializerResolverInterface
{
    public function hasSerializerFor(string $type): bool
    {
        return $type === 'tags';
    }

    public function serializerFor(string $type): \haddowg\JsonApi\Serializer\SerializerInterface
    {
        return new MembershipTagResource();
    }

    public function relationshipLoadState(): ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface
    {
        return null;
    }

    public function relationshipCount(): ?\haddowg\JsonApi\Serializer\RelationshipCountInterface
    {
        return null;
    }

    public function relationshipPagination(): ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface
    {
        return null;
    }

    public function relationshipLinkage(): ?\haddowg\JsonApi\Serializer\RelationshipLinkageInterface
    {
        return null;
    }

    public function resourceLinkContributor(): ?\haddowg\JsonApi\Serializer\ResourceLinkContributorInterface
    {
        return null;
    }
}
