<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;

/**
 * The mutable **field builder** for a string attribute. Adds the string-specific
 * length / pattern / format helpers on top of the common {@see AbstractFieldBuilder}
 * surface; {@see build()} freezes it into a readonly {@see Str} value object.
 *
 * Non-final by design: the format-preset facades ({@see Email}, {@see Url},
 * {@see Uuid}, {@see Slug}, {@see Ip}) extend it to preset a format constraint and
 * add a type-named authoring shortcut — they build the same base {@see Str}.
 */
class StrBuilder extends AbstractFieldBuilder
{
    public function build(): Str
    {
        return new Str($this->fieldState());
    }

    /**
     * @return static
     */
    public function minLength(int $length): static
    {
        return $this->addConstraint(new MinLength($length, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function maxLength(int $length): static
    {
        return $this->addConstraint(new MaxLength($length, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function pattern(string $regex): static
    {
        return $this->addConstraint(new Pattern($regex, $this->currentContext()));
    }

    /**
     * @param bool $strict opt into RFC-compliant validation (default HTML5-style)
     * @return static
     */
    public function email(bool $strict = false): static
    {
        return $this->addConstraint(new EmailFormat($strict, $this->currentContext()));
    }

    /**
     * @param list<string> $allowedSchemes
     * @return static
     */
    public function url(array $allowedSchemes = []): static
    {
        return $this->addConstraint(new UrlFormat($allowedSchemes, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function uuid(?int $version = null): static
    {
        return $this->addConstraint(new UuidFormat($version, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function slug(?string $regex = null): static
    {
        $this->addConstraint(
            $regex === null
                ? new SlugFormat(context: $this->currentContext())
                : new SlugFormat($regex, $this->currentContext()),
        );

        // Without an explicit example a renderer synthesises a gibberish string from
        // the slug pattern. Preset a readable one for the default slug shape (a custom
        // regex may not match it, so only the default form gets a default); an author
        // `->example(…)` still wins.
        if ($regex === null && !$this->hasExample) {
            $this->example('example-slug');
        }

        return $this;
    }

    /**
     * @return static
     */
    public function ip(?int $version = null): static
    {
        return $this->addConstraint(new IpFormat($version, $this->currentContext()));
    }
}
