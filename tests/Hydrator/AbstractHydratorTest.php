<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator;

use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Tests\Hydrator\Double\StubHydrator;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the core hydration logic in HydratorTrait as exercised through
 * AbstractHydrator (which composes both create and update traits).
 *
 * Hydrators throw typed exceptions directly (no factory). Relationship linkage
 * is read via public properties (`$owner->resourceIdentifier`,
 * `$children->resourceIdentifiers`). Requests are built with Nyholm PSR-7.
 */
final class AbstractHydratorTest extends TestCase
{
    #[Test]
    public function validateTypeWhenMissing(): void
    {
        $body = [
            'data' => [],
        ];

        $hydrator = $this->createHydrator();

        $this->expectException(ResourceTypeMissing::class);
        $hydrator->hydrateForCreate($this->createRequest($body), []);
    }

    #[Test]
    public function validateTypeWhenUnacceptableAndOnlyOneAcceptable(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
            ],
        ];

        $hydrator = $this->createHydrator(['fox']);

        $this->expectException(ResourceTypeUnacceptable::class);
        $hydrator->hydrateForCreate($this->createRequest($body), []);
    }

    #[Test]
    public function validateTypeWhenUnacceptableAndMoreAcceptable(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
            ],
        ];

        $hydrator = $this->createHydrator(['fox', 'wolf']);

        $this->expectException(ResourceTypeUnacceptable::class);
        $hydrator->hydrateForUpdate($this->createRequest($body), []);
    }

    #[Test]
    public function hydrateAttributesWhenEmpty(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
            ],
        ];

        $hydrator = $this->createHydrator(['elephant']);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals([], $domainObject);
    }

    #[Test]
    public function hydrateAttributesWhenNull(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'attributes' => [
                    'height' => null,
                ],
            ],
        ];
        $attributeHydrator = [
            'height' => function (array &$elephant, mixed $attribute): void {
                $elephant['height'] = $attribute;
            },
        ];

        $hydrator = $this->createHydrator(['elephant'], $attributeHydrator);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals(['height' => null], $domainObject);
    }

    #[Test]
    public function hydrateAttributesWhenHydratorEmpty(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'attributes' => [
                    'height' => 2.5,
                ],
            ],
        ];
        $attributeHydrator = [
            'weight' => function (array &$elephant, mixed $attribute): void {
                $elephant['weight'] = $attribute;
            },
        ];

        $hydrator = $this->createHydrator(['elephant'], $attributeHydrator);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals([], $domainObject);
    }

    #[Test]
    public function hydrateAttributesWhenHydratorReturnByReference(): void
    {
        $weight = 1000;
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'attributes' => [
                    'weight' => $weight,
                ],
            ],
        ];
        $attributeHydrator = [
            'weight' => function (array &$elephant, mixed $attribute): void {
                $elephant['weight'] = $attribute;
            },
        ];

        $hydrator = $this->createHydrator(['elephant'], $attributeHydrator);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals(['weight' => $weight], $domainObject);
    }

    #[Test]
    public function hydrateAttributesWhenHydratorReturnByValue(): void
    {
        $weight = 1000;
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'attributes' => [
                    'weight' => $weight,
                ],
            ],
        ];
        $attributeHydrator = [
            'weight' => function (array $elephant, mixed $attribute): array {
                $elephant['weight'] = $attribute;

                return $elephant;
            },
        ];

        $hydrator = $this->createHydrator(['elephant'], $attributeHydrator);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals(['weight' => $weight], $domainObject);
    }

    #[Test]
    public function hydrateRelationshipsWhenHydratorEmpty(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'relationships' => [
                    'parents' => [],
                ],
            ],
        ];
        $relationshipHydrator = [
            'children' => function (array &$elephant, ToManyRelationship $children): void {
                $elephant['children'] = ['Dumbo', 'Mambo'];
            },
        ];

        $hydrator = $this->createHydrator(['elephant'], [], $relationshipHydrator);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals([], $domainObject);
    }

    #[Test]
    public function hydrateRelationshipsWhenCardinalityInappropriate(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'relationships' => [
                    'children' => [
                        'data' => [
                            'type' => 'elephant',
                            'id' => '2',
                        ],
                    ],
                ],
            ],
        ];
        $relationshipHydrator = [
            'children' => function (array &$elephant, ToManyRelationship $children): void {
                $elephant['children'] = $children->resourceIdentifiers;
            },
        ];
        $hydrator = $this->createHydrator(['elephant'], [], $relationshipHydrator);

        $this->expectException(RelationshipTypeInappropriate::class);
        $hydrator->hydrateForUpdate($this->createRequest($body), []);
    }

    #[Test]
    public function hydrateRelationshipsWhenCardinalityInappropriate2(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'relationships' => [
                    'children' => [
                        'data' => [
                            [
                                'type' => 'elephant',
                                'id' => '2',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $relationshipHydrator = [
            'children' => function (array &$elephant, ToOneRelationship $children): void {
                $elephant['children'] = $children->resourceIdentifier;
            },
        ];
        $hydrator = $this->createHydrator(['elephant'], [], $relationshipHydrator);

        $this->expectException(RelationshipTypeInappropriate::class);
        $hydrator->hydrateForUpdate($this->createRequest($body), []);
    }

    #[Test]
    public function hydrateRelationshipsWhenExpectedCardinalityIsNotSet(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'relationships' => [
                    'children' => [
                        'data' => [
                            [
                                'type' => 'elephant',
                                'id' => '2',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $relationshipHydrator = [
            'children' => function (array &$elephant, mixed $children): void {
                $elephant['children'] = 'Dumbo';
            },
        ];

        $hydrator = $this->createHydrator(['elephant'], [], $relationshipHydrator);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals(['children' => 'Dumbo'], $domainObject);
    }

    #[Test]
    public function hydrateRelationships(): void
    {
        $body = [
            'data' => [
                'type' => 'elephant',
                'id' => '1',
                'relationships' => [
                    'owner' => [
                        'data' => [
                            'type' => 'person',
                            'id' => '1',
                        ],
                    ],
                    'children' => [
                        'data' => [
                            [
                                'type' => 'elephant',
                                'id' => '2',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $relationshipHydrator = [
            'owner' => function (array $elephant, ToOneRelationship $owner): array {
                // Access the public property directly (no getter in modern API).
                $elephant['owner'] = $owner->resourceIdentifier !== null ? $owner->resourceIdentifier->id : '';

                return $elephant;
            },
            'children' => function (array &$elephant, ToManyRelationship $children): void {
                $elephant['children'] = $children->getResourceIdentifierIds();
            },
        ];

        $hydrator = $this->createHydrator(['elephant'], [], $relationshipHydrator);
        $domainObject = $hydrator->hydrateForUpdate($this->createRequest($body), []);
        self::assertEquals(['owner' => '1', 'children' => ['2']], $domainObject);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRequest(array $body): JsonApiRequest
    {
        $json = \json_encode($body);
        if ($json === false) {
            $json = '';
        }

        $stream = Stream::create($json);

        $psrRequest = (new ServerRequest('POST', '/'))
            ->withParsedBody($body)
            ->withBody($stream);

        return new JsonApiRequest($psrRequest);
    }

    /**
     * @param list<string> $acceptedTypes
     * @param array<string, callable> $attributeHydrator
     * @param array<string, callable> $relationshipHydrator
     */
    private function createHydrator(
        array $acceptedTypes = [],
        array $attributeHydrator = [],
        array $relationshipHydrator = [],
    ): AbstractHydrator {
        return new StubHydrator($acceptedTypes, $attributeHydrator, $relationshipHydrator);
    }
}
