<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Transformer;

use haddowg\JsonApi\Exception\RelationshipCountNotAllowed;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Document\CollectionDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\CountableControlsInterface;
use haddowg\JsonApi\Serializer\CountableSelfInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the request's flat `?withCount` against the primary resource's
 * declared countable relationships, up front through the real
 * {@see DocumentTransformer} → {@see SingleResourceDocument}/{@see CollectionDocument}
 * path — the same root-scoped seam the include allow-list runs through.
 */
#[Group('spec:fetching-data')]
final class RelationshipCountValidationTest extends TestCase
{
    /**
     * `?withCount` is gated behind the Countable profile, so every request
     * that exercises it negotiates the profile URI in its `Accept`.
     *
     * @var array<string, string>
     */
    private const array COUNTS_ACCEPT = ['Accept' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

    #[Test]
    public function aCountableRelationNamedInWithCountIsPermitted(): void
    {
        $serializer = new CountableSerializer('posts', '1', countable: ['comments']);

        $result = $this->render($serializer, ['id' => '1'], new StubJsonApiRequest(['withCount' => 'comments'], self::COUNTS_ACCEPT));

        self::assertSame('posts', $this->primaryType($result));
    }

    #[Test]
    public function aNonCountableRelationNamedInWithCountIs400(): void
    {
        // 'comments' is a relationship but not declared countable().
        $serializer = new CountableSerializer('posts', '1', countable: []);

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->render($serializer, ['id' => '1'], new StubJsonApiRequest(['withCount' => 'comments'], self::COUNTS_ACCEPT));
    }

    #[Test]
    public function aToOneRelationNamedInWithCountIs400(): void
    {
        // A to-one relation never appears in the countable set (count is to-many
        // only), so naming it in ?withCount is rejected the same way.
        $serializer = new CountableSerializer('posts', '1', countable: ['comments']);

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->render($serializer, ['id' => '1'], new StubJsonApiRequest(['withCount' => 'author'], self::COUNTS_ACCEPT));
    }

    #[Test]
    public function aSerializerWithoutCountableControlsRejectsAnyWithCount(): void
    {
        // Counting is opt-in: a serializer that is not CountableControlsInterface
        // declares no countable relationships, so any ?withCount is rejected.
        $serializer = new PlainSerializer('posts', '1');

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->render($serializer, ['id' => '1'], new StubJsonApiRequest(['withCount' => 'comments'], self::COUNTS_ACCEPT));
    }

    #[Test]
    public function anAbsentWithCountIsANoOp(): void
    {
        $serializer = new PlainSerializer('posts', '1');

        $result = $this->render($serializer, ['id' => '1'], new StubJsonApiRequest());

        self::assertSame('posts', $this->primaryType($result));
    }

    #[Test]
    public function theSelfTokenIsPermittedOnACountableSelfSerializer(): void
    {
        $serializer = new CountableSelfSerializer('posts', '1', selfCountable: true);

        $result = $this->render($serializer, ['id' => '1'], new StubJsonApiRequest(['withCount' => CountableProfile::SELF_TOKEN], self::COUNTS_ACCEPT));

        self::assertSame('posts', $this->primaryType($result));
    }

    #[Test]
    public function theSelfTokenIs400OnANonCountableSelfSerializer(): void
    {
        // The resource is CountableSelfInterface but isCountable() is false: _self_
        // counting is opt-in, so the token is rejected.
        $serializer = new CountableSelfSerializer('posts', '1', selfCountable: false);

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->render($serializer, ['id' => '1'], new StubJsonApiRequest(['withCount' => CountableProfile::SELF_TOKEN], self::COUNTS_ACCEPT));
    }

    #[Test]
    public function theSelfTokenIs400OnABareSerializerLackingTheCapability(): void
    {
        // A serializer that is not CountableSelfInterface is not _self_-countable —
        // the safe default (counting is opt-in).
        $serializer = new PlainSerializer('posts', '1');

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->render($serializer, ['id' => '1'], new StubJsonApiRequest(['withCount' => CountableProfile::SELF_TOKEN], self::COUNTS_ACCEPT));
    }

    #[Test]
    public function theSelfTokenAndACountableRelationCompose(): void
    {
        $serializer = new CountableSelfSerializer('posts', '1', selfCountable: true, countable: ['comments']);

        $result = $this->render(
            $serializer,
            ['id' => '1'],
            new StubJsonApiRequest(['withCount' => CountableProfile::SELF_TOKEN . ',comments'], self::COUNTS_ACCEPT),
        );

        self::assertSame('posts', $this->primaryType($result));
    }

    #[Test]
    public function theSelfTokenComposedWithANonCountableRelationStill400s(): void
    {
        $serializer = new CountableSelfSerializer('posts', '1', selfCountable: true, countable: []);

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->render(
            $serializer,
            ['id' => '1'],
            new StubJsonApiRequest(['withCount' => CountableProfile::SELF_TOKEN . ',comments'], self::COUNTS_ACCEPT),
        );
    }

    #[Test]
    public function theSelfTokenOverrideGatesOnTheOverrideNotThePrimarySerializer(): void
    {
        // A related-collection render supplies the owning relation's countable() as the
        // override: `_self_` is then permitted even though the primary (related-type)
        // serializer is NOT itself _self_-countable — the relation, whose endpoint this
        // is, governs the gate (core ADR 0068).
        $serializer = new PlainSerializer('posts', '1');

        $result = $this->render(
            $serializer,
            ['id' => '1'],
            new StubJsonApiRequest(['withCount' => CountableProfile::SELF_TOKEN], self::COUNTS_ACCEPT),
            countableSelfOverride: true,
        );

        self::assertSame('posts', $this->primaryType($result));
    }

    #[Test]
    public function theSelfTokenOverrideFalseRejectsEvenACountableSelfPrimary(): void
    {
        // The inverse: a `false` override (the owning relation is not countable) rejects
        // `_self_` even when the primary serializer would itself be _self_-countable.
        $serializer = new CountableSelfSerializer('posts', '1', selfCountable: true);

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->render(
            $serializer,
            ['id' => '1'],
            new StubJsonApiRequest(['withCount' => CountableProfile::SELF_TOKEN], self::COUNTS_ACCEPT),
            countableSelfOverride: false,
        );
    }

    #[Test]
    public function theValidationAlsoRunsOnTheCollectionRoot(): void
    {
        // The collection path wires the up-front root validation through a distinct
        // call site from the single-resource path, so it gets its own assertion.
        $serializer = new CountableSerializer('posts', '1', countable: []);

        $this->expectException(RelationshipCountNotAllowed::class);

        $this->renderCollection($serializer, [['id' => '1']], new StubJsonApiRequest(['withCount' => 'comments'], self::COUNTS_ACCEPT));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function primaryType(array $result): ?string
    {
        $data = $result['data'] ?? null;
        if (\is_array($data) === false) {
            return null;
        }

        $type = $data['type'] ?? null;

        return \is_string($type) ? $type : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function render(
        SerializerInterface $serializer,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
        ?bool $countableSelfOverride = null,
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
            null,
            $countableSelfOverride,
        );

        return (new DocumentTransformer())->transformResourceDocument($transformation)->result;
    }

    /**
     * @param list<mixed> $objects
     *
     * @return array<string, mixed>
     */
    private function renderCollection(
        SerializerInterface $serializer,
        array $objects,
        ?JsonApiRequestInterface $request = null,
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
            null,
        );

        return (new DocumentTransformer())->transformResourceDocument($transformation)->result;
    }
}

/**
 * A minimal serializer declaring its countable relationship set.
 */
final class CountableSerializer extends AbstractSerializer implements CountableControlsInterface
{
    /**
     * @param list<string> $countable
     */
    public function __construct(
        private readonly string $type,
        private readonly string $id,
        private readonly array $countable = [],
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
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getCountableRelationships(mixed $object): array
    {
        return $this->countable;
    }
}

/**
 * A serializer declaring its primary collection countable via the `_self_` token
 * (and optionally its countable relationships), proving the resource-level gate.
 */
final class CountableSelfSerializer extends AbstractSerializer implements CountableControlsInterface, CountableSelfInterface
{
    /**
     * @param list<string> $countable
     */
    public function __construct(
        private readonly string $type,
        private readonly string $id,
        private readonly bool $selfCountable = false,
        private readonly array $countable = [],
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
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getCountableRelationships(mixed $object): array
    {
        return $this->countable;
    }

    public function isCountable(): bool
    {
        return $this->selfCountable;
    }
}

/**
 * A serializer that is NOT {@see CountableControlsInterface} — used to prove
 * counting is opt-in (any ?withCount against it is rejected).
 */
final class PlainSerializer extends AbstractSerializer
{
    public function __construct(
        private readonly string $type,
        private readonly string $id,
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
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
