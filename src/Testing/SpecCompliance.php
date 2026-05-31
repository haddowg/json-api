<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing;

use haddowg\JsonApi\Exception\ResponseBodyInvalidJsonApi;
use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Testing\Internal\Decode;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One-line spec-compliance assertion: validates a document against the JSON:API
 * 1.1 base (response) schema and converts any violation into a PHPUnit failure
 * that lists each offending pointer + message.
 *
 * Available both as the static {@see assert()} helper and (via the
 * {@see AssertsSpecCompliance} trait) as a PHPUnit-style
 * `assertJsonApiSpecCompliant()` method. Requires the optional
 * `opis/json-schema` package (through {@see DocumentValidator}); install it in
 * the test environment.
 */
final class SpecCompliance
{
    /**
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     */
    public static function assert(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
        ?DocumentValidator $validator = null,
    ): void {
        $parsed = Decode::toArray($document, $server, $request);
        $validator ??= new DocumentValidator(new VendoredSchemaProvider());

        try {
            $validator->validateResponse($parsed);
        } catch (ResponseBodyInvalidJsonApi $exception) {
            $lines = \array_map(
                static function (array $violation): string {
                    $pointer = $violation['property'] ?? '/';

                    return "  {$pointer}: {$violation['message']}";
                },
                $exception->validationErrors,
            );

            Assert::fail("Document is not JSON:API spec-compliant:\n" . \implode("\n", $lines));
        }
    }
}
