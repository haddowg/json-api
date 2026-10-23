<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Operation;
use haddowg\JsonApi\OpenApi\Parameter;
use haddowg\JsonApi\OpenApi\PathItem;
use haddowg\JsonApi\OpenApi\Response;
use haddowg\JsonApi\OpenApi\Responses;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\VendorExtensions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VendorExtensions::class)]
#[CoversClass(Operation::class)]
#[CoversClass(PathItem::class)]
#[CoversClass(Parameter::class)]
#[CoversClass(Response::class)]
final class VendorExtensionsTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function vos(): iterable
    {
        yield 'operation' => [static fn(): Operation => new Operation(new Responses(), summary: 'List')];
        yield 'path item' => [static fn(): PathItem => new PathItem(summary: 'Articles')];
        yield 'parameter' => [static fn(): Parameter => Parameter::query('sort', Schema::ofType('string'))];
        yield 'response' => [static fn(): Response => new Response('OK')];
    }

    /**
     * @param \Closure(): (Operation|PathItem|Parameter|Response) $make
     */
    #[Test]
    #[DataProvider('vos')]
    public function extensionsRenderLastInBothForms(\Closure $make): void
    {
        $vo = $make()
            ->withExtension('feature', 'agency')
            ->withExtensions(['x-flags' => ['beta' => true], 'owner' => 'billing']);

        $array = $vo->toArray();
        self::assertSame(
            ['x-feature', 'x-flags', 'x-owner'],
            \array_slice(\array_keys($array), -3),
        );
        self::assertSame('agency', $array['x-feature']);
        self::assertSame(['beta' => true], $array['x-flags']);

        $json = $vo->toJson();
        self::assertSame('agency', $json->{'x-feature'});
        self::assertEquals((object) ['beta' => true], $json->{'x-flags'});
        self::assertSame(
            ['x-feature', 'x-flags', 'x-owner'],
            \array_slice(\array_keys(\get_object_vars($json)), -3),
        );

        self::assertSame('agency', $vo->extension('feature'));
        self::assertSame('billing', $vo->extension('x-owner'));
        self::assertNull($vo->extension('missing'));
    }

    /**
     * @param \Closure(): (Operation|PathItem|Parameter|Response) $make
     */
    #[Test]
    #[DataProvider('vos')]
    public function aVoWithoutExtensionsEmitsNone(\Closure $make): void
    {
        self::assertSame([], \preg_grep('/^x-/', \array_keys($make()->toArray())));
    }

    /**
     * @param \Closure(): (Operation|PathItem|Parameter|Response) $make
     */
    #[Test]
    #[DataProvider('vos')]
    public function aReservedNameIsRejected(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');

        $make()->withExtension('x-oas-internal', true);
    }

    #[Test]
    public function aConstructorMapMustAlreadyUseXKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"feature"');

        new Operation(new Responses(), extensions: ['feature' => 'agency']);
    }

    #[Test]
    public function aBarePrefixIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Response('OK', extensions: ['x-' => true]);
    }

    #[Test]
    public function constructorExtensionsAreKeptByTheWithers(): void
    {
        $item = (new PathItem(extensions: ['x-feature' => 'agency']))
            ->withOperation('get', new Operation(new Responses()));

        self::assertSame('agency', $item->toArray()['x-feature']);
        self::assertArrayHasKey('get', $item->toArray());
    }
}
