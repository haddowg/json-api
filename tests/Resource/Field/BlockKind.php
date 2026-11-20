<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

enum BlockKind: string
{
    case Text = 'text';
    case Image = 'image';
    case Divider = 'divider';
}
