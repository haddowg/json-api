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
 * A generic string attribute. The `email()` / `url()` / `uuid()` / `slug()` /
 * `ip()` shortcuts append the same format constraint the dedicated field types
 * carry, so `Str::make('contact')->email()` and `Email::make('contact')`
 * produce identical metadata.
 */
class Str extends AbstractField
{
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
        return $this->addConstraint(
            $regex === null
                ? new SlugFormat(context: $this->currentContext())
                : new SlugFormat($regex, $this->currentContext()),
        );
    }

    /**
     * @return static
     */
    public function ip(?int $version = null): static
    {
        return $this->addConstraint(new IpFormat($version, $this->currentContext()));
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (\is_scalar($raw) ? (string) $raw : $raw);
    }
}
