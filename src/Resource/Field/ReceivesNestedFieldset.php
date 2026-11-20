<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The `extractUsing()` / `computedUsing()` / `serializeUsing()` hooks of a builder whose
 * field a request can narrow, each receiving the nested fieldset as its fourth argument.
 */
trait ReceivesNestedFieldset
{
    /**
     * Renders the read value from `$callback`, called with the model, the request, the
     * member's name and the keys a request selected, `null` when it selected the whole
     * attribute. The callback owns the value's shape, and the value is still trimmed to
     * the selected keys whatever it returns, so it may skip the keys it was not asked for.
     *
     * @template TModel
     *
     * @param \Closure(TModel, JsonApiRequestInterface, string, ?NestedFieldset): mixed $callback
     *
     * @return static
     */
    public function serializeUsing(\Closure $callback): static
    {
        $this->serializeUsing = $callback;

        return $this;
    }

    /**
     * Supplies the read value from `$callback`, called with the model, the request, the
     * member's name and the keys a request selected, `null` when it selected the whole
     * attribute. The value is trimmed to the selected keys whatever the callback returns,
     * and a list's nested fieldset holds the keys of every item under {@see NestedFieldset::items()}.
     *
     * @template TModel
     *
     * @param \Closure(TModel, JsonApiRequestInterface, string, ?NestedFieldset): mixed $callback
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
     * @param \Closure(TModel, JsonApiRequestInterface, string, ?NestedFieldset): mixed $callback
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
