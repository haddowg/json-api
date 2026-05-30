<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Data;

use haddowg\JsonApi\Schema\Data\SingleResourceData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SingleResourceDataTest extends TestCase
{
    #[Test]
    public function getNonExistentResource(): void
    {
        $resources = [
            ['type' => 'resource', 'id' => '1'],
        ];

        $data = $this->createData()->setIncludedResources($resources);

        self::assertNull($data->getResource('resource', '2'));
        self::assertNull($data->getResource('resources', '1'));
    }

    #[Test]
    public function getResource(): void
    {
        $resource = ['type' => 'resource', 'id' => '1'];

        $data = $this->createData()->addIncludedResource($resource);

        self::assertEquals($resource, $data->getResource('resource', '1'));
    }

    #[Test]
    public function isEmptyByDefault(): void
    {
        self::assertFalse($this->createData()->hasIncludedResources());
    }

    #[Test]
    public function hasIncludedResourcesAfterSet(): void
    {
        $resources = [['type' => 'resource', 'id' => '1']];

        $data = $this->createData()->setIncludedResources($resources);

        self::assertTrue($data->hasIncludedResources());
    }

    #[Test]
    public function hasNoIncludedResourcesWhenSetWithEmpty(): void
    {
        $data = $this->createData()->setIncludedResources([]);

        self::assertFalse($data->hasIncludedResources());
    }

    #[Test]
    public function addResource(): void
    {
        $resource = ['type' => 'resource', 'id' => '1'];

        $data = $this->createData()->addIncludedResource($resource);

        self::assertEquals($resource, $data->getResource('resource', '1'));
    }

    #[Test]
    public function transformIncludedReturnsEmptyWhenNone(): void
    {
        self::assertEquals([], $this->createData()->transformIncluded());
    }

    #[Test]
    public function transformIncludedDeduplicates(): void
    {
        $data = $this->createData()->setIncludedResources([
            ['type' => 'item', 'id' => '1'],
            ['type' => 'resource', 'id' => '2'],
            ['type' => 'resource', 'id' => '1'],
            ['type' => 'item', 'id' => '2'],
            ['type' => 'item', 'id' => '1'],     // duplicate
            ['type' => 'resource', 'id' => '2'], // duplicate
        ]);

        self::assertEquals(
            [
                ['type' => 'item', 'id' => '1'],
                ['type' => 'resource', 'id' => '2'],
                ['type' => 'resource', 'id' => '1'],
                ['type' => 'item', 'id' => '2'],
            ],
            $data->transformIncluded(),
        );
    }

    #[Test]
    public function transformPrimaryDataReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->createData()->transformPrimaryData());
    }

    #[Test]
    public function transformSinglePrimaryResource(): void
    {
        $data = $this->createData();
        $data->addPrimaryResource(['type' => 'user', 'id' => '1']);

        self::assertEquals(['type' => 'user', 'id' => '1'], $data->transformPrimaryData());
    }

    #[Test]
    public function transformPrimaryDataReturnsFirstResourceWhenMultipleAdded(): void
    {
        $data = $this->createData();
        $data->setPrimaryResources(
            [
                ['type' => 'user', 'id' => '1'],
                ['type' => 'user', 'id' => '2'],
                ['type' => 'dog', 'id' => '4'],
                ['type' => 'dog', 'id' => '3'],
                ['type' => 'user', 'id' => '3'],
            ],
        );

        self::assertEquals(['type' => 'user', 'id' => '1'], $data->transformPrimaryData());
    }

    private function createData(): SingleResourceData
    {
        return new SingleResourceData();
    }
}
