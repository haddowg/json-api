<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Error;

use haddowg\JsonApi\Schema\Link\ErrorLinks;

/**
 * A single JSON:API error object.
 *
 * Construct-only and immutable: every member is supplied at construction. All
 * members are optional per the spec; absent string members are the empty string
 * and absent structured members are `null`, and each is omitted from
 * {@see transform()} accordingly. Use named arguments for readable construction:
 * `new Error(status: '404', code: 'NOT_FOUND', title: 'Resource not found')`.
 *
 * @see https://jsonapi.org/format/1.1/#error-objects
 */
final readonly class Error
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $id = '',
        public string $status = '',
        public string $code = '',
        public string $title = '',
        public string $detail = '',
        public ?ErrorSource $source = null,
        public ?ErrorLinks $links = null,
        public array $meta = [],
    ) {}

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        $content = [];

        if ($this->id !== '') {
            $content['id'] = $this->id;
        }

        if ($this->meta !== []) {
            $content['meta'] = $this->meta;
        }

        if ($this->links !== null) {
            $content['links'] = $this->links->transform();
        }

        if ($this->status !== '') {
            $content['status'] = $this->status;
        }

        if ($this->code !== '') {
            $content['code'] = $this->code;
        }

        if ($this->title !== '') {
            $content['title'] = $this->title;
        }

        if ($this->detail !== '') {
            $content['detail'] = $this->detail;
        }

        if ($this->source !== null) {
            $content['source'] = $this->source->transform();
        }

        return $content;
    }
}
