<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\Resource\Enum\DescribedEnum;
use haddowg\JsonApi\Resource\Enum\DescribesEnumCases;
use haddowg\JsonApi\Resource\Enum\EnumCaseDescription;

/**
 * A string-backed enum that opts into per-case descriptions, used by the
 * projector tests.
 */
enum Status: string implements DescribedEnum
{
    use DescribesEnumCases;

    #[EnumCaseDescription('Not yet visible to readers')]
    case Draft = 'draft';

    #[EnumCaseDescription('Live and public')]
    case Published = 'published';

    // Deliberately undescribed, to prove a missing description degrades gracefully.
    case Archived = 'archived';
}
