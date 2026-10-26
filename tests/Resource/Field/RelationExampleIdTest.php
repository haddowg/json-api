<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\ExemplifiedRelationInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractRelationBuilder::class)]
final class RelationExampleIdTest extends TestCase
{
    #[Test]
    public function aRelationCarriesTheExampleIdItDeclares(): void
    {
        $relation = BelongsTo::make('company', 'companies')->exampleId('co-1')->build();

        self::assertInstanceOf(ExemplifiedRelationInterface::class, $relation);
        self::assertSame('co-1', $relation->exampleId());
        self::assertSame('7', HasMany::make('tags', 'tags')->exampleId(7)->build()->exampleId());
        self::assertSame('t-1', BelongsToMany::make('tags', 'tags')->exampleId('t-1')->build()->exampleId());
    }

    #[Test]
    public function aRelationDeclaresNoExampleIdByDefault(): void
    {
        self::assertNull(BelongsTo::make('company', 'companies')->build()->exampleId());
    }
}
