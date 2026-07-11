<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The mutable **filter builder** for a {@see WhereAll} (AND) group. {@see build()}
 * freezes the accumulated state — building any child builder — into a readonly
 * {@see WhereAll} value object.
 */
final class WhereAllBuilder extends WhereGroupBuilder
{
    public function build(): WhereAll
    {
        return new WhereAll($this->key, $this->buildChildren(), $this->constraints, $this->description, $this->hasExample, $this->example);
    }
}
