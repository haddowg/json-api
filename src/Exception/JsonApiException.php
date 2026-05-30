<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

/**
 * Contract for exceptions that carry JSON:API error data.
 *
 * Unlike yin's `JsonApiExceptionInterface`, an exception exposes the error
 * *data* ({@see Error} value objects) and the HTTP status to respond with — it
 * does not build an error document. Assembling the document is the
 * responsibility of the serialization layer.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#errors
 */
interface JsonApiException extends \Throwable
{
    /**
     * The JSON:API error objects describing what went wrong.
     *
     * @return list<Error>
     */
    public function getErrors(): array;

    /**
     * The HTTP status code the response should carry.
     */
    public function getStatusCode(): int;
}
