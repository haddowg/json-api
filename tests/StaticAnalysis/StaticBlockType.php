<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

enum StaticBlockType: string
{
    case Text = 'text';
    case Image = 'image';
    case Divider = 'divider';
}
