<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Reference;
use haddowg\JsonApi\OpenApi\Response;
use haddowg\JsonApi\OpenApi\Responses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An operation's responses serialize in status order whatever order they were added in.
 */
#[CoversClass(Responses::class)]
final class ResponsesTest extends TestCase
{
    #[Test]
    public function responsesSerializeInStatusOrder(): void
    {
        $responses = (new Responses())
            ->with('default', new Response('Anything else.'))
            ->with('500', Reference::to('responses', 'InternalServerError'))
            ->with('204', new Response('Deleted.'))
            ->with('4XX', new Response('A client error.'))
            ->with('200', new Response('Deleted, with meta.'))
            ->with('409', Reference::to('responses', 'Conflict'))
            ->with('401', Reference::to('responses', 'Unauthorized'));

        $expected = ['200', '204', '401', '409', '4XX', '500', 'default'];
        self::assertSame($expected, \array_map(\strval(...), \array_keys($responses->toArray())));
        self::assertSame($expected, \array_map(\strval(...), \array_keys(\get_object_vars($responses->toJson()))));
    }
}
