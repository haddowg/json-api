<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The `extractUsing()` / `computedUsing()` hooks of a builder whose field a request can
 * narrow, each receiving the selection as its fourth argument.
 */
trait ReceivesKeySelection
{
    /**
     * Supplies the read value from `$callback`, called with the model, the request, the
     * member's name and the keys a request selected, `null` when it selected the whole
     * attribute. The value is trimmed to the selected keys whatever the callback returns,
     * and a list's selection holds the keys of every item under {@see KeySelection::items()}.
     *
     * @template TModel
     *
     * @param \Closure(TModel, JsonApiRequestInterface, string, ?KeySelection): mixed $callback
     *
     * @return static
     */
    public function extractUsing(\Closure $callback): static
    {
        $this->assertNotFlattened();
        $this->extractUsing = $callback;

        return $this;
    }

    /**
     * A derived, read-only value from `$callback`, which receives the selected keys as
     * {@see extractUsing()} does.
     *
     * @template TModel
     *
     * @param \Closure(TModel, JsonApiRequestInterface, string, ?KeySelection): mixed $callback
     *
     * @return static
     */
    public function computedUsing(\Closure $callback): static
    {
        $this->assertNotFlattened();
        $this->computed();
        $this->extractUsing = $callback;

        return $this->readOnly();
    }

    private function assertNotFlattened(): void
    {
        if ($this->relatedVia !== null) {
            throw new \LogicException(\sprintf(
                'Field "%s" cannot combine extractUsing()/computedUsing() with on(): a '
                . 'flattened related attribute reads its own backing member off the '
                . 'related object.',
                $this->name,
            ));
        }
    }
}
