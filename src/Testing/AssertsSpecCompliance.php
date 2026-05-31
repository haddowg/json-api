<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Validation\DocumentValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PHPUnit-style assertion trait wrapping {@see SpecCompliance::assert()}. Mix
 * into a `TestCase` to call `$this->assertJsonApiSpecCompliant($response)`.
 */
trait AssertsSpecCompliance
{
    /**
     * @param ResponseInterface|string|array<string, mixed>|AbstractResponse $document
     */
    protected function assertJsonApiSpecCompliant(
        ResponseInterface|string|array|AbstractResponse $document,
        ?ServerInterface $server = null,
        ?ServerRequestInterface $request = null,
        ?DocumentValidator $validator = null,
    ): void {
        SpecCompliance::assert($document, $server, $request, $validator);
    }
}
