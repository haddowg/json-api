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

        if ($this->errors === []) {
            return 500;
        }

        // A document whose errors all carry the same status takes that status
        // verbatim: a bag of validation `422`s is a `422`, not a rounded-down
        // `400`. Only a genuinely mixed set falls back to the nearest applicable
        // status class. (A single error is trivially uniform.)
        $firstStatus = $this->errors[0]->status;
        foreach ($this->errors as $error) {
            if ($error->status !== $firstStatus) {
                return $this->roundedStatusClass();
            }
        }

        return (int) $firstStatus;
    }

    /**
     * The nearest applicable status class for a set of errors spanning more than
     * one status: rounds each error's status down to its hundred and keeps the
     * highest class that is at least one full class away from the running result.
     */
    private function roundedStatusClass(): int
    {
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
