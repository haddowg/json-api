<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereAny} (OR) group. {@see build()}
 * freezes the accumulated state — building any child builder — into a readonly
 * {@see WhereAny} value object.
 */
final class WhereAnyBuilder extends WhereGroupBuilder
{
    public function build(): WhereAny
    {
        return new WhereAny($this->key, $this->buildChildren(), $this->constraints, $this->description, $this->hasExample, $this->example);
    }
}
