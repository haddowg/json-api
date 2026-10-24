<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Serve;

use Composer\InstalledVersions;
use haddowg\JsonApi\OpenApi\Components;
use haddowg\JsonApi\OpenApi\Info;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifactCodec;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use haddowg\JsonApi\Tests\OpenApi\Serve\Fixture\Tripwire;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServeArtifactCodec::class)]
final class ServeArtifactCodecTest extends TestCase
{
    protected function setUp(): void
    {
        Tripwire::$woken = false;
    }

    #[Test]
    public function aProjectedDocumentRoundTrips(): void
    {
        $artifact = ServeArtifact::of((new OpenApiProjector())->project(ContractWitnessServer::build()));
        $codec = new ServeArtifactCodec();

        $decoded = $codec->decode($codec->encode($artifact));

        self::assertNotNull($decoded);
        self::assertEquals($artifact->document->toJson(), $decoded->document->toJson());
        self::assertEquals($artifact->baseline, $decoded->baseline);
    }

    #[Test]
    public function theFingerprintNamesCoreAndTheAdapterPackages(): void
    {
        $fingerprint = (new ServeArtifactCodec(['phpunit/phpunit', 'acme/not-installed']))->fingerprint();

        self::assertSame(
            'haddowg/json-api@' . InstalledVersions::getVersion('haddowg/json-api') . '#' . InstalledVersions::getReference('haddowg/json-api')
            . ';phpunit/phpunit@' . InstalledVersions::getVersion('phpunit/phpunit') . '#' . InstalledVersions::getReference('phpunit/phpunit')
            . ';acme/not-installed@-',
            $fingerprint,
        );
    }

    #[Test]
    public function anArtifactWrittenUnderAnotherFingerprintReadsAsMissing(): void
    {
        $bytes = (new ServeArtifactCodec(['phpunit/phpunit']))->encode(self::artifact());

        self::assertNull((new ServeArtifactCodec())->decode($bytes));
        self::assertNotNull((new ServeArtifactCodec(['phpunit/phpunit']))->decode($bytes));
    }

    #[Test]
    public function anArtifactFromAnotherCoreVersionReadsAsMissing(): void
    {
        $codec = new ServeArtifactCodec();
        $bytes = $codec->encode(self::artifact());

        $stale = \str_replace($codec->fingerprint(), 'haddowg/json-api@0.0.1#abc', $bytes);

        self::assertNull($codec->decode($stale));
    }

    #[Test]
    public function unreadableBytesReadAsMissing(): void
    {
        $codec = new ServeArtifactCodec();
        $bytes = $codec->encode(self::artifact());

        self::assertNull($codec->decode(''));
        self::assertNull($codec->decode('not an artifact'));
        self::assertNull($codec->decode(\substr($bytes, 0, -20)));
        self::assertNull($codec->decode(\serialize(self::artifact())));
    }

    #[Test]
    public function anEnumValueRoundTrips(): void
    {
        $codec = new ServeArtifactCodec();

        $decoded = $codec->decode($codec->encode(self::artifact(Schema::ofType('string')->withExample(Status::Draft))));

        self::assertNotNull($decoded);
        self::assertSame(Status::Draft, (fn(): mixed => $this->keywords['example'])->call($decoded->document->components->schemas['Example']));
        self::assertStringContainsString('"example":"draft"', $decoded->document->toJsonString());
    }

    #[Test]
    public function anObjectOutsideTheAllowListCannotBeEncoded(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Tripwire::class);

        (new ServeArtifactCodec())->encode(self::artifact(Schema::ofType('string')->withExample(new Tripwire())));
    }

    #[Test]
    public function anAllowedClassRoundTrips(): void
    {
        $codec = new ServeArtifactCodec(allowedClasses: [Tripwire::class]);

        $decoded = $codec->decode($codec->encode(self::artifact(Schema::ofType('string')->withExample(new Tripwire('kept')))));

        self::assertNotNull($decoded);
        self::assertStringContainsString('"example":"kept"', $decoded->document->toJsonString());
    }

    #[Test]
    public function aClassSmuggledIntoTheBytesIsNeverInstantiated(): void
    {
        $allowing = new ServeArtifactCodec(allowedClasses: [Tripwire::class]);
        $bytes = $allowing->encode(self::artifact(Schema::ofType('string')->withExample(new Tripwire())));

        $decoded = (new ServeArtifactCodec())->decode($bytes);

        self::assertFalse(Tripwire::$woken);
        self::assertNotNull($decoded);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, (fn(): mixed => $this->keywords['example'])->call($decoded->document->components->schemas['Example']));
    }

    #[Test]
    public function theAllowListCoversEveryOpenApiValueObject(): void
    {
        $allowed = (new \ReflectionClass(ServeArtifactCodec::class))->getConstant('CLASSES');
        self::assertIsArray($allowed);

        $directory = \dirname((string) (new \ReflectionClass(OpenApi::class))->getFileName());
        foreach (\scandir($directory) ?: [] as $file) {
            if (!\str_ends_with($file, '.php')) {
                continue;
            }
            $class = 'haddowg\JsonApi\OpenApi\\' . \substr($file, 0, -4);
            if (\is_subclass_of($class, \JsonSerializable::class)) {
                self::assertContains($class, $allowed, $class . ' is a value object the codec does not allow');
            }
        }
    }

    private static function artifact(?Schema $example = null): ServeArtifact
    {
        $components = new Components();
        if ($example !== null) {
            $components = $components->withSchema('Example', $example);
        }

        return ServeArtifact::of(new OpenApi(new Info('Catalog', '1.0.0'), $components));
    }
}
