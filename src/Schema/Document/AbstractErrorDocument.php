<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\Error\Error;

/**
 * Base for error documents. Accumulates {@see Error} value objects and derives
 * the document's HTTP status code from them.
 *
 * @internal
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
abstract class AbstractErrorDocument implements ErrorDocumentInterface
{
    /**
     * @var list<Error>
     */
    protected array $errors = [];

    /**
     * @return list<Error>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Includes a new error in the error document.
     *
     * @return $this
     */
    public function addError(Error $error): static
    {
        $this->errors[] = $error;

        return $this;
    }

    public function getStatusCode(?int $statusCode = null): int
    {
        if ($statusCode !== null) {
            return $statusCode;
        }

        if (\count($this->errors) === 1) {
            return (int) $this->errors[0]->status;
        }

        $result = 500;
        foreach ($this->errors as $error) {
            $roundedStatusCode = (int) ((int) $error->status / 100) * 100;

            if (\abs($result - $roundedStatusCode) >= 100) {
                $result = $roundedStatusCode;
            }
        }

        return $result;
    }
}
