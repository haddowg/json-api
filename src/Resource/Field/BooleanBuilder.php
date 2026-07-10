<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutable **field builder** for a boolean attribute. Carries only the common
 * {@see AbstractFieldBuilder} authoring surface; {@see build()} freezes it into a
 * readonly {@see Boolean} value object.
 */
final class BooleanBuilder extends AbstractFieldBuilder
{
    public function build(): Boolean
    {
        return new Boolean($this->fieldState());
    }
}
