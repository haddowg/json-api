<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Transformer;

use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\Exception\InclusionNotAllowed;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Document\CollectionDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the three compound-document include safeguards, exercised
 * through the real {@see DocumentTransformer} → {@see SingleResourceDocument}
 * path: per-relation includable opt-out (A), the root allowed-include-paths
 * whitelist (C), and max include depth (B).
 */
#[Group('spec:inclusion-of-related-resources')]
final class IncludeSafeguardsTest extends TestCase
{
    #[Test]
    public function aNonIncludableRelationRequestedViaIncludeIs400(): void
    {
        // 'secret' exists as a relationship but is non-includable.
        $serializer = new ControllableSerializer(
            type: 'posts',
            id: '1',
            relationships: ['secret' => $this->toOne('secrets', '9')],
            nonIncludable: ['secret'],
        );

        $this->expectException(InclusionNotAllowed::class);

        $this->render($serializer, ['post'], new StubJsonApiRequest(['include' => 'secret']));
    }

    #[Test]
    public function aNonIncludableRelationIsOmittedFromTheDefaultIncludeCascade(): void
    {
        // Two default-includes: 'tag' is includable, 'secret' is not. The cascade
        // fires when ?include is ABSENT entirely. The includable default expands;
        // the non-includable one is dropped from the cascade.
        $serializer = new ControllableSerializer(
            type: 'posts',
            id: '1',
            relationships: [
                'tag' => $this->toOne('tags', '5'),
                'secret' => $this->toOne('secrets', '9'),
            ],
            defaultIncludes: ['tag', 'secret'],
            nonIncludable: ['secret'],
        );

        $result = $this->render($serializer, ['post'], new StubJsonApiRequest());

        self::assertSame([['type' => 'tags', 'id' => '5']], $this->included($result));
    }

    #[Test]
    public function aRequestedIncludeDeeperThanTheServerCapIs400(): void
    {
        $serializer = $this->chainOfDepth(4);

        $this->expectException(InclusionDepthExceeded::class);

        $this->render(
            $serializer,
            ['post'],
            new StubJsonApiRequest(['include' => 'next.next.next.next']),
            maxIncludeDepth: 3,
        );
    }

    #[Test]
    public function aRequestedIncludeWithinTheServerCapSucceeds(): void
    {
        $serializer = $this->chainOfDepth(4);

        $result = $this->render(
            $serializer,
            ['post'],
            new StubJsonApiRequest(['include' => 'next.next.next']),
            maxIncludeDepth: 3,
        );

        // a.b.c is depth 3 — allowed; three included resources expand.
        self::assertCount(3, $this->included($result));
    }

    #[Test]
    public function aMutualDefaultIncludeCycleTerminatesAtTheCap(): void
    {
        // A default-include ring a -> b -> c -> a -> ... Each node default-includes
        // 'next'. Without a cap the transformer descends forever (the descent
        // recurses BEFORE the included-set dedup can reject a revisit), so reaching
        // the assertion at all proves termination. With a cap of 2 the 'included'
        // expansion is also bounded to depth 2.
        $a = new ControllableSerializer('a', '1', defaultIncludes: ['next']);
        $b = new ControllableSerializer('b', '2', defaultIncludes: ['next']);
        $c = new ControllableSerializer('c', '3', defaultIncludes: ['next']);
        $a->relationships = ['next' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '2'], $b)];
        $b->relationships = ['next' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '3'], $c)];
        $c->relationships = ['next' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '1'], $a)];

        $result = $this->render($a, ['id' => '1'], new StubJsonApiRequest(), maxIncludeDepth: 2);

        // Depth-1 'next' (b) and depth-2 'next' (c) expand; depth-3 ('next' back to
        // a) is silently capped. Exactly 2 included resources, and we returned.
        $included = $this->included($result);
        self::assertCount(2, $included);
        $identities = [];
        foreach ($included as $resource) {
            self::assertIsString($resource['type']);
            self::assertIsString($resource['id']);
            $identities[] = $resource['type'] . ':' . $resource['id'];
        }
        \sort($identities);
        self::assertSame(['b:2', 'c:3'], $identities);
    }

    #[Test]
    public function aPerResourceOverrideBeatsTheServerDefault(): void
    {
        // Server default is generous (5) but the root caps itself at 1.
        $serializer = $this->chainOfDepth(3, rootMaxDepth: 1);

        $this->expectException(InclusionDepthExceeded::class);

        $this->render(
            $serializer,
            ['post'],
            new StubJsonApiRequest(['include' => 'next.next']),
            maxIncludeDepth: 5,
        );
    }

    #[Test]
    public function aNullOrZeroCapMeansUnlimited(): void
    {
        $serializer = $this->chainOfDepth(4);

        // Zero is normalised to unlimited; a deep include is fine.
        $result = $this->render(
            $serializer,
            ['post'],
            new StubJsonApiRequest(['include' => 'next.next.next.next']),
            maxIncludeDepth: 0,
        );

        self::assertCount(4, $this->included($result));
    }

    #[Test]
    public function theRootAllowListForbidsANestedPathEvenWhenTheRelationIsIncludable(): void
    {
        // posts -> comments (includable) -> author. The root allows only 'comments',
        // so ?include=comments.author is rejected even though 'author' is includable
        // when a comment is the root.
        $author = new ControllableSerializer('users', '7');
        $comment = new ControllableSerializer('comments', '3', relationships: [
            'author' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '7'], $author),
        ]);
        $post = new ControllableSerializer('posts', '1', relationships: [
            'comments' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '3'], $comment),
        ], allowedIncludePaths: ['comments']);

        $this->expectException(InclusionNotAllowed::class);

        $this->render($post, ['id' => '1'], new StubJsonApiRequest(['include' => 'comments.author']));
    }

    #[Test]
    public function theRootAllowListPermitsAListedPath(): void
    {
        $comment = new ControllableSerializer('comments', '3');
        $post = new ControllableSerializer('posts', '1', relationships: [
            'comments' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '3'], $comment),
        ], allowedIncludePaths: ['comments']);

        $result = $this->render($post, ['id' => '1'], new StubJsonApiRequest(['include' => 'comments']));

        self::assertSame([['type' => 'comments', 'id' => '3']], $this->included($result));
    }

    #[Test]
    public function theRootAllowListImpliesTheAncestorsOfAListedDeepPath(): void
    {
        // The root lists only the deep path 'comments.author'; requesting it
        // succeeds even though the intermediate 'comments' is not itself listed —
        // a listed deep path implies its ancestors are traversable (prefix
        // semantics, matching Laravel's allowedIncludePaths).
        $author = new ControllableSerializer('users', '7');
        $comment = new ControllableSerializer('comments', '3', relationships: [
            'author' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '7'], $author),
        ]);
        $post = new ControllableSerializer('posts', '1', relationships: [
            'comments' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '3'], $comment),
        ], allowedIncludePaths: ['comments.author']);

        $result = $this->render($post, ['id' => '1'], new StubJsonApiRequest(['include' => 'comments.author']));

        self::assertCount(2, $this->included($result));
    }

    #[Test]
    public function theRootAllowListForbidsASiblingOfAListedDeepPath(): void
    {
        // Allowing 'comments.author' implies 'comments' but NOT a sibling nested
        // path like 'comments.editor' (a declared, includable relation) — it is
        // not a listed path nor an ancestor of one, so it is rejected.
        $author = new ControllableSerializer('users', '7');
        $editor = new ControllableSerializer('users', '8');
        $comment = new ControllableSerializer('comments', '3', relationships: [
            'author' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '7'], $author),
            'editor' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '8'], $editor),
        ]);
        $post = new ControllableSerializer('posts', '1', relationships: [
            'comments' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '3'], $comment),
        ], allowedIncludePaths: ['comments.author']);

        $this->expectException(InclusionNotAllowed::class);

        $this->render($post, ['id' => '1'], new StubJsonApiRequest(['include' => 'comments.editor']));
    }

    #[Test]
    public function aNullAllowListLeavesIncludesUnrestricted(): void
    {
        // Same shape as the forbidding case, but no allow-list set: the nested
        // include succeeds (back-compat).
        $author = new ControllableSerializer('users', '7');
        $comment = new ControllableSerializer('comments', '3', relationships: [
            'author' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '7'], $author),
        ]);
        $post = new ControllableSerializer('posts', '1', relationships: [
            'comments' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '3'], $comment),
        ]);

        $result = $this->render($post, ['id' => '1'], new StubJsonApiRequest(['include' => 'comments.author']));

        self::assertCount(2, $this->included($result));
    }

    #[Test]
    public function aCollectionRootedRequestDeeperThanTheCapIs400(): void
    {
        // The collection path wires the cap through a distinct call site
        // (AbstractCollectionDocument::getData) from the single-resource path, so
        // it gets its own over-depth assertion.
        $serializer = $this->chainOfDepth(4);

        $this->expectException(InclusionDepthExceeded::class);

        $this->renderCollection(
            $serializer,
            [['post'], ['post']],
            new StubJsonApiRequest(['include' => 'next.next.next.next']),
            maxIncludeDepth: 3,
        );
    }

    #[Test]
    public function aCollectionRootedDefaultIncludeCycleTerminatesAtTheCap(): void
    {
        // A circular default-include ring rendered as the primary collection. As
        // with the single-resource case, reaching the assertion proves the cascade
        // terminates rather than recursing forever.
        $a = new ControllableSerializer('a', '1', defaultIncludes: ['next']);
        $b = new ControllableSerializer('b', '2', defaultIncludes: ['next']);
        $c = new ControllableSerializer('c', '3', defaultIncludes: ['next']);
        $a->relationships = ['next' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '2'], $b)];
        $b->relationships = ['next' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '3'], $c)];
        $c->relationships = ['next' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => '1'], $a)];

        $result = $this->renderCollection($a, [['id' => '1']], new StubJsonApiRequest(), maxIncludeDepth: 2);

        // Depth-1 (b) and depth-2 (c) expand; depth-3 (back to a) is silently capped.
        $included = $this->included($result);
        self::assertCount(2, $included);
        $identities = [];
        foreach ($included as $resource) {
            self::assertIsString($resource['type']);
            self::assertIsString($resource['id']);
            $identities[] = $resource['type'] . ':' . $resource['id'];
        }
        \sort($identities);
        self::assertSame(['b:2', 'c:3'], $identities);
    }

    /**
     * @return callable(mixed, JsonApiRequestInterface, string): ToOneRelationship
     */
    private function toOne(string $type, string $id): callable
    {
        $related = new ControllableSerializer($type, $id);

        return static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => $id], $related);
    }

    /**
     * Builds a `next -> next -> ...` self-similar chain `$depth` links long. Each
     * link's serializer exposes a single to-one relation named `next` pointing at
     * the next link, so `?include=next.next…` walks the chain.
     */
    private function chainOfDepth(int $depth, ?int $rootMaxDepth = null): ControllableSerializer
    {
        $serializers = [];
        for ($i = 0; $i <= $depth; ++$i) {
            $serializers[$i] = new ControllableSerializer('node', (string) ($i + 1), maxDepth: $i === 0 ? $rootMaxDepth : null);
        }

        for ($i = 0; $i < $depth; ++$i) {
            $next = $serializers[$i + 1];
            $nextId = (string) ($i + 2);
            $serializers[$i]->relationships = [
                'next' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(['id' => $nextId], $next),
            ];
        }

        return $serializers[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function render(
        SerializerInterface $serializer,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
        ?int $maxIncludeDepth = null,
    ): array {
        $document = new SingleResourceDocument($serializer, null, [], null);

        $transformation = new ResourceDocumentTransformation(
            $document,
            $object,
            $request ?? new StubJsonApiRequest(),
            '',
            '',
            [],
            '',
            $maxIncludeDepth,
        );

        $result = (new DocumentTransformer())->transformResourceDocument($transformation)->result;

        return $result;
    }

    /**
     * Renders a primary COLLECTION through the real {@see CollectionDocument} path,
     * the distinct call site that {@see AbstractCollectionDocument::getData} wires
     * the include safeguards through.
     *
     * @param list<mixed> $objects
     *
     * @return array<string, mixed>
     */
    private function renderCollection(
        SerializerInterface $serializer,
        array $objects,
        ?JsonApiRequestInterface $request = null,
        ?int $maxIncludeDepth = null,
    ): array {
        $document = new CollectionDocument($serializer, null, [], null);

        $transformation = new ResourceDocumentTransformation(
            $document,
            $objects,
            $request ?? new StubJsonApiRequest(),
            '',
            '',
            [],
            '',
            $maxIncludeDepth,
        );

        $result = (new DocumentTransformer())->transformResourceDocument($transformation)->result;

        return $result;
    }

    /**
     * Extracts the compound document's `included` member as a typed list.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private function included(array $result): array
    {
        $included = $result['included'] ?? [];
        self::assertIsArray($included);

        /** @var list<array<string, mixed>> $included */
        return $included;
    }
}

/**
 * A configurable {@see SerializerInterface} that also carries the three include
 * controls. Relationships are mutable so cyclic / chained fixtures can be wired
 * after construction.
 */
final class ControllableSerializer extends AbstractSerializer implements IncludeControlsInterface
{
    /**
     * @param array<string, callable(mixed, JsonApiRequestInterface, string): \haddowg\JsonApi\Schema\Relationship\AbstractRelationship> $relationships
     * @param list<string>                                                                                                              $defaultIncludes
     * @param list<string>                                                                                                              $nonIncludable
     * @param list<string>|null                                                                                                         $allowedIncludePaths
     */
    public function __construct(
        private readonly string $type,
        private readonly string $id,
        public array $relationships = [],
        private readonly array $defaultIncludes = [],
        private readonly array $nonIncludable = [],
        private readonly ?int $maxDepth = null,
        private readonly ?array $allowedIncludePaths = null,
    ) {}

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
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->defaultIncludes;
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->relationships;
    }

    public function getNonIncludableRelationships(mixed $object): array
    {
        return $this->nonIncludable;
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->maxDepth;
    }

    public function getAllowedIncludePaths(): ?array
    {
        return $this->allowedIncludePaths;
    }
}
