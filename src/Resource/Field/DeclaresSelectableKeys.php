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
    use ReceivesKeySelection;

    private ?SelectableKeys $selectableKeys = null;

    /**
     * Lets a request select this attribute's keys one by one with
     * `fields[TYPE]=<attr>.<key>`, for each key `$pattern` matches whole and `$accepts`
     * returns `true` for. Give either or both. A key the declaration rejects is an
     * unrecognized fieldset member. When this object is a key of another that declares
     * selectable keys, a request can reach its keys as `<attr>.<key>.<key>`, and when it
     * is the element of a list, as `<attr>.*.<key>` in every item.
     *
     * @param (\Closure(string, JsonApiRequestInterface): bool)|null $accepts
     * @param string|null                                           $pattern an ECMA-262 and PCRE regular expression a whole key must match, which can match no `.`, `,` or `*`
     *
     * @return static
     *
     * @throws \InvalidArgumentException when neither is given, or the pattern is not valid or can match a `.`, `,` or `*`
     */
    public function selectableKeys(?\Closure $accepts = null, ?string $pattern = null): static
    {
        if ($accepts === null && $pattern === null) {
            throw new \InvalidArgumentException(\sprintf('Field "%s" declares selectableKeys() with neither a predicate nor a pattern.', $this->name()));
        }
        if ($pattern !== null) {
            \haddowg\JsonApi\Resource\Internal\KeyFamilyMatcher::assertValidSuffixPattern($pattern, 'selectable key of the', $this->name());
            // A request splits its members at every comma and its paths at every full stop,
            // and `*` stands for every item of a list, so a key holding one is never asked
            // for, yet the OpenAPI document would offer it.
            foreach (['.', ',', '*'] as $character) {
                if (Internal\PatternCharacters::canMatch($pattern, $character)) {
                    throw new \InvalidArgumentException(\sprintf('Field "%s" declares selectableKeys() with the pattern "%s", which can match "%s". A request splits its fields members at every comma and its paths at every full stop, and "*" stands for every item of a list, so no key holds any of them: exclude them from the pattern, as [^.,*]+ does.', $this->name(), $pattern, $character));
                }
            }
        }

        $this->selectableKeys = new SelectableKeys($accepts, $pattern);

        return $this;
    }
}
