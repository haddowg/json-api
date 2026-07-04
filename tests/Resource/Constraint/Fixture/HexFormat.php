<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Constraint\Fixture;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;

/**
 * A consumer-defined constraint that self-describes its JSON Schema. Proves an
 * application can attach a constraint outside core's vocabulary (via
 * `->constrain()`) and have it appear in BOTH the OpenAPI projection and the
 * body-validation schema with no core change — the whole point of
 * {@see ProvidesJsonSchema}. It contributes a standard keyword (`pattern`) and a
 * vendor extension (`x-hex`) so both surfaces are exercised.
 */
final readonly class HexFormat implements ProvidesJsonSchema
{
    public const string PATTERN = '^[0-9a-f]+$';

    public function __construct(
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withPattern(self::PATTERN)->withExtension('hex', true);
    }
}
