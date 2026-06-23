<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Transformer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\DummyData;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-resource-object-links')]
final class ResourceLinkContributorTest extends TestCase
{
    #[Test]
    public function aContributorAddsItsLinkToTheResourceLinks(): void
    {
        $resource = new ResolverAwareLinkResource('user', '1');
        $resource->setSerializerResolver($this->resolverWith(
            new FakeLinkContributor(['describedby' => new Link('/docs/user')]),
        ));

        $resourceObject = $this->toResourceObject($resource, [], 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
                'links' => [
                    'describedby' => 'https://api.test/docs/user',
                    // The convention `self` is still added (c).
                    'self' => 'https://api.test/user/1',
                ],
            ],
            $resourceObject,
        );
    }

    #[Test]
    public function aContributorDoesNotOverrideAnAuthorSuppliedKeyOfTheSameName(): void
    {
        // The author's getLinks() declares `describedby` -> /authored; the
        // contributor declares the same key -> /contributed. Author wins (b).
        $resource = new ResolverAwareLinkResource(
            'user',
            '1',
            ResourceLinks::withBaseUri('https://api.test', links: ['describedby' => new Link('/authored')]),
        );
        $resource->setSerializerResolver($this->resolverWith(
            new FakeLinkContributor(['describedby' => new Link('/contributed')]),
        ));

        $resourceObject = $this->toResourceObject($resource, [], 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
                'links' => [
                    'describedby' => 'https://api.test/authored',
                    'self' => 'https://api.test/user/1',
                ],
            ],
            $resourceObject,
        );
    }

    #[Test]
    public function withNoContributorTheOutputIsUnchanged(): void
    {
        // A resolver carrying no contributor leaves the links exactly as getLinks()
        // (plus the convention self) produce (d).
        $resource = new ResolverAwareLinkResource('user', '1');
        $resource->setSerializerResolver($this->resolverWith(null));

        $resourceObject = $this->toResourceObject($resource, [], 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => 'https://api.test/user/1'],
            ],
            $resourceObject,
        );
    }

    #[Test]
    public function aContributorReturningNothingAddsNothing(): void
    {
        $resource = new ResolverAwareLinkResource('user', '1');
        $resource->setSerializerResolver($this->resolverWith(new FakeLinkContributor([])));

        $resourceObject = $this->toResourceObject($resource, [], 'https://api.test');

        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => 'https://api.test/user/1'],
            ],
            $resourceObject,
        );
    }

    private function resolverWith(?ResourceLinkContributorInterface $contributor): SerializerResolverInterface
    {
        return new FakeContributorResolver($contributor);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toResourceObject(SerializerInterface $resource, mixed $object, string $baseUri): ?array
    {
        $transformation = new ResourceTransformation(
            $resource,
            $object,
            '',
            new StubJsonApiRequest(),
            '',
            '',
            '',
            $baseUri,
        );

        return (new ResourceTransformer())->transformToResourceObject($transformation, new DummyData());
    }
}

/**
 * A resolver-aware serializer (a bare serializer that opted into the resolver, like
 * a standalone serializer would) used to verify the out-of-band link contributor is
 * reached off the rendered resource's resolver.
 */
final class ResolverAwareLinkResource extends AbstractSerializer implements SerializerResolverAwareInterface
{
    private ?SerializerResolverInterface $resolver = null;

    public function __construct(
        private readonly string $type,
        private readonly string $id,
        private readonly ?ResourceLinks $links = null,
    ) {}

    public function setSerializerResolver(SerializerResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function serializerResolver(): ?SerializerResolverInterface
    {
        return $this->resolver;
    }

    public function getType(mixed $object): string
    {
        return $this->type;
    }

    public function getId(mixed $object): string
    {
        return $this->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->links;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}

/**
 * A fake {@see ResourceLinkContributorInterface} returning a fixed set of named links.
 */
final class FakeLinkContributor implements ResourceLinkContributorInterface
{
    /**
     * @param array<string, Link> $links
     */
    public function __construct(private readonly array $links) {}

    public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array
    {
        return $this->links;
    }
}

/**
 * A minimal {@see SerializerResolverInterface} carrying only a link contributor; the
 * other seams are inert (null), as the standalone library leaves them.
 */
final class FakeContributorResolver implements SerializerResolverInterface
{
    public function __construct(private readonly ?ResourceLinkContributorInterface $contributor) {}

    public function serializerFor(string $type): SerializerInterface
    {
        throw new \RuntimeException('not used');
    }

    public function hasSerializerFor(string $type): bool
    {
        return false;
    }

    public function relationshipLoadState(): ?RelationshipLoadStateInterface
    {
        return null;
    }

    public function relationshipCount(): ?RelationshipCountInterface
    {
        return null;
    }

    public function relationshipPagination(): ?RelationshipPaginationInterface
    {
        return null;
    }

    public function relationshipLinkage(): ?RelationshipLinkageInterface
    {
        return null;
    }

    public function resourceLinkContributor(): ?ResourceLinkContributorInterface
    {
        return $this->contributor;
    }
}
