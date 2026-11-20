<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

/**
 * The kinds of an article section, the discriminator of the contract witness's union list.
 */
enum SectionKind: string
{
    case Heading = 'heading';
    case Figure = 'figure';
    case Rule = 'rule';
}
