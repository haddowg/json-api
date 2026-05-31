<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;

/**
 * Concrete error document carrying an optional jsonapi object, meta and links
 * alongside its errors.
 *
 * @internal
 *
 */
class ErrorDocument extends AbstractErrorDocument
{
    protected ?JsonApiObject $jsonApi = null;

    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    protected ?DocumentLinks $links = null;

    /**
     * @param list<Error> $errors
     */
    public static function create(array $errors = []): ErrorDocument
    {
        return new ErrorDocument($errors);
    }

    /**
     * @param list<Error> $errors
     */
    public function __construct(array $errors = [])
    {
        foreach ($errors as $error) {
            $this->addError($error);
        }
    }

    public function getJsonApi(): ?JsonApiObject
    {
        return $this->jsonApi;
    }

    public function setJsonApi(?JsonApiObject $jsonApi): ErrorDocument
    {
        $this->jsonApi = $jsonApi;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): ErrorDocument
    {
        $this->meta = $meta;

        return $this;
    }

    public function getLinks(): ?DocumentLinks
    {
        return $this->links;
    }

    public function setLinks(?DocumentLinks $links): ErrorDocument
    {
        $this->links = $links;

        return $this;
    }
}
