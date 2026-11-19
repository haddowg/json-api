<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The `selectableKeys()` declaration of an object attribute's builder, and the
 * `extractUsing()` / `computedUsing()` hooks that receive the selected keys.
 */
trait DeclaresSelectableKeys
{
    private ?SelectableKeys $selectableKeys = null;

    /**
     * Lets a request select this attribute's keys one by one with
     * `fields[TYPE]=<attr>.<key>`, for each key `$pattern` matches whole and `$accepts`
     * returns `true` for. Give either or both. A key the declaration rejects is an
     * unrecognized fieldset member.
     *
     * @param (\Closure(string, JsonApiRequestInterface): bool)|null $accepts
     * @param string|null                                           $pattern an ECMA-262 and PCRE regular expression a whole key must match
     *
     * @return static
     */
    public function selectableKeys(?\Closure $accepts = null, ?string $pattern = null): static
    {
        if ($accepts === null && $pattern === null) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" declares selectableKeys() with neither a predicate nor a pattern.', $this->name()));
        }
        if ($pattern !== null) {
            \haddowg\JsonApi\Resource\Internal\KeyFamilyMatcher::assertValidSuffixPattern($pattern, 'selectable key of the', $this->name());
        }

        $this->selectableKeys = new SelectableKeys($accepts, $pattern);

        return $this;
    }

    /**
     * Supplies the read value from `$callback`, called with the model, the request, the
     * member's name and the keys a request selected, `null` when it selected the whole
     * attribute. The value is trimmed to the selected keys whatever the callback returns.
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
