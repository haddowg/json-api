<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Data;

use haddowg\JsonApi\Schema\Data\CollectionData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CollectionDataTest extends TestCase
{
    #[Test]
    public function transformPrimaryDataReturnsEmptyArrayWhenNone(): void
    {
        self::assertEquals([], $this->createData()->transformPrimaryData());
    }

    #[Test]
    public function transformSinglePrimaryResourceInOrderDefined(): void
    {
        $data = $this->createData();
        $data->addPrimaryResource(['type' => 'user', 'id' => '1']);

        self::assertEquals(
            [
                ['type' => 'user', 'id' => '1'],
            ],
            $data->transformPrimaryData(),
        );
    }

    #[Test]
    public function transformMultiplePrimaryResourcesInOrderDefined(): void
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

        self::assertEquals(
            [
                ['type' => 'user', 'id' => '1'],
                ['type' => 'user', 'id' => '2'],
                ['type' => 'dog', 'id' => '4'],
                ['type' => 'dog', 'id' => '3'],
                ['type' => 'user', 'id' => '3'],
            ],
            $data->transformPrimaryData(),
        );
    }

    #[Test]
    public function transformPrimaryDataDeduplicatesResources(): void
    {
        $data = $this->createData();
        $data->setPrimaryResources(
            [
                ['type' => 'user', 'id' => '1'],
                ['type' => 'user', 'id' => '1'], // duplicate — should be skipped
                ['type' => 'user', 'id' => '2'],
            ],
        );

        self::assertEquals(
            [
                ['type' => 'user', 'id' => '1'],
                ['type' => 'user', 'id' => '2'],
            ],
            $data->transformPrimaryData(),
        );
    }

    #[Test]
    public function includedResourcesNotReturnedInPrimaryData(): void
    {
        $data = $this->createData();
        $data->addPrimaryResource(['type' => 'user', 'id' => '1']);
        $data->addIncludedResource(['type' => 'post', 'id' => '10']);

        self::assertEquals(
            [['type' => 'user', 'id' => '1']],
            $data->transformPrimaryData(),
        );
    }

    private function createData(): CollectionData
    {
        return new CollectionData();
    }
}
