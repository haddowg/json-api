<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Data;

use haddowg\JsonApi\Schema\Data\AbstractData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AbstractData using an anonymous concrete subclass.
 *
 * @internal
 */
final class AbstractDataTest extends TestCase
{
    #[Test]
    public function setPrimaryResources(): void
    {
        $data = $this->createData();
        $data->setPrimaryResources(
            [
                ['type' => 'user', 'id' => '1'],
                ['type' => 'user', 'id' => '2'],
            ],
        );

        self::assertTrue($data->hasPrimaryResource('user', '1'));
        self::assertTrue($data->hasPrimaryResource('user', '2'));
    }

    #[Test]
    public function addNotYetIncludedPrimaryResource(): void
    {
        $data = $this->createData();
        $data->addPrimaryResource(['type' => 'user', 'id' => '1']);

        self::assertTrue($data->hasPrimaryResource('user', '1'));
    }

    #[Test]
    public function addAlreadyIncludedPrimaryResource(): void
    {
        $data = $this->createData();
        $data->addIncludedResource(['type' => 'user', 'id' => '1']);
        $data->addPrimaryResource(['type' => 'user', 'id' => '1']);

        self::assertFalse($data->hasIncludedResource('user', '1'));
        self::assertTrue($data->hasPrimaryResource('user', '1'));
    }

    #[Test]
    public function setPrimaryResourcesClearsPreviousPrimaryKeys(): void
    {
        $data = $this->createData();
        $data->addPrimaryResource(['type' => 'user', 'id' => '99']);
        $data->setPrimaryResources([['type' => 'user', 'id' => '1']]);

        self::assertFalse($data->hasPrimaryResource('user', '99'));
        self::assertTrue($data->hasPrimaryResource('user', '1'));
    }

    #[Test]
    public function setIncludedResourcesClearsPreviousIncludedKeys(): void
    {
        $data = $this->createData();
        $data->addIncludedResource(['type' => 'item', 'id' => '99']);
        $data->setIncludedResources([['type' => 'item', 'id' => '1']]);

        self::assertFalse($data->hasIncludedResource('item', '99'));
        self::assertTrue($data->hasIncludedResource('item', '1'));
    }

    #[Test]
    public function includedResourceIsNotAddedWhenAlreadyPrimary(): void
    {
        $data = $this->createData();
        $data->addPrimaryResource(['type' => 'user', 'id' => '1']);
        $data->addIncludedResource(['type' => 'user', 'id' => '1']);

        self::assertFalse($data->hasIncludedResource('user', '1'));
        self::assertTrue($data->hasPrimaryResource('user', '1'));
    }

    #[Test]
    public function hasPrimaryResourcesReturnsFalseWhenEmpty(): void
    {
        self::assertFalse($this->createData()->hasPrimaryResources());
    }

    #[Test]
    public function hasIncludedResourcesReturnsFalseWhenEmpty(): void
    {
        self::assertFalse($this->createData()->hasIncludedResources());
    }

    #[Test]
    public function hasIncludedResourcesReturnsTrueAfterAdd(): void
    {
        $data = $this->createData();
        $data->addIncludedResource(['type' => 'item', 'id' => '1']);

        self::assertTrue($data->hasIncludedResources());
    }

    #[Test]
    public function getResourceReturnsNullForUnknownResource(): void
    {
        $data = $this->createData();
        $data->addPrimaryResource(['type' => 'user', 'id' => '1']);

        self::assertNull($data->getResource('user', '2'));
        self::assertNull($data->getResource('users', '1'));
    }

    #[Test]
    public function getResourceReturnsKnownResource(): void
    {
        $resource = ['type' => 'user', 'id' => '1'];
        $data = $this->createData();
        $data->addIncludedResource($resource);

        self::assertSame($resource, $data->getResource('user', '1'));
    }

    #[Test]
    public function transformIncludedDeduplicatesResources(): void
    {
        $data = $this->createData();
        $data->setIncludedResources([
            ['type' => 'item', 'id' => '1'],
            ['type' => 'resource', 'id' => '2'],
            ['type' => 'resource', 'id' => '1'],
            ['type' => 'item', 'id' => '2'],
            ['type' => 'item', 'id' => '1'],   // duplicate — should be skipped
            ['type' => 'resource', 'id' => '2'], // duplicate — should be skipped
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
    public function transformIncludedReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->createData()->transformIncluded());
    }

    private function createData(): AbstractData
    {
        return new class extends AbstractData {
            /**
             * @return array<int, array<string, mixed>>
             */
            public function transformPrimaryData(): array
            {
                return [];
            }
        };
    }
}
