<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Error;

/**
 * The "source" member of an error object, locating the cause of the error.
 *
 * @see https://jsonapi.org/format/1.1/#error-objects
 */
final readonly class ErrorSource
{
    public function __construct(
        public string $pointer,
        public string $parameter,
        public string $header = '',
    ) {}

    /**
     * A JSON Pointer (RFC 6901) to the value in the request document that caused the error.
     */
    public static function fromPointer(string $pointer): self
    {
        return new self($pointer, '');
    }

    /**
     * The name of the query parameter that caused the error.
     */
    public static function fromParameter(string $parameter): self
    {
        return new self('', $parameter);
    }

    /**
     * The name of the request header that caused the error.
     */
    public static function fromHeader(string $header): self
    {
        return new self('', '', $header);
    }

    /**
     * @internal Serializes the object to its JSON:API representation.
     *
     * @return array<string, string>
     */
    public function transform(): array
    {
        $content = [];

        if ($this->pointer !== '') {
            $content['pointer'] = $this->pointer;
        }

        if ($this->parameter !== '') {
            $content['parameter'] = $this->parameter;
        }

        if ($this->header !== '') {
            $content['header'] = $this->header;
        }

        return $content;
    }
}
