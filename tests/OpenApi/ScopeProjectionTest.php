<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OAuthFlow;
use haddowg\JsonApi\OpenApi\OAuthFlows;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationScopes;
use haddowg\JsonApi\OpenApi\Projection;
use haddowg\JsonApi\OpenApi\ScopeSchemeNotOAuth2;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\SecuritySchemeType;
use haddowg\JsonApi\OpenApi\UndeclaredScope;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Scope\ScopeTable;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeDeclaringServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeTables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each operation's `security` names the OAuth scopes it needs under the scope scheme,
 * whose flows list the catalogue; a document needing none is unchanged.
 */
#[CoversClass(OperationScopes::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class ScopeProjectionTest extends TestCase
{
    #[Test]
    public function aTableNeedingNoScopeLeavesTheDocumentUnchanged(): void
    {
        $plain = $this->server();
        $none = new ScopeDeclaringServerMetadata($plain, ScopeTables::of($plain, static fn(): array => []));
        $empty = new ScopeDeclaringServerMetadata($plain, new ScopeTable(), scheme: 'missing');

        self::assertSame($this->json($plain), $this->json($none));
        self::assertSame($this->json($plain), $this->json($empty));
    }

    #[Test]
    public function aRequirementNamingTheSchemeListsTheScopes(): void
    {
        $document = $this->project($this->scoped(default: [SecurityRequirement::scheme('oauth2')]));

        self::assertSame([['oauth2' => ['books:read']]], $this->security($document, '/books/{id}', 'get'));
        self::assertSame([['oauth2' => ['books:write']]], $this->security($document, '/books/{id}', 'patch'));
    }

    #[Test]
    public function anOperationThatInheritedTheDefaultGetsItsOwn(): void
    {
        $document = $this->project($this->scoped(default: [SecurityRequirement::scheme('bearer'), SecurityRequirement::scheme('oauth2')]));

        self::assertSame([['bearer' => []], ['oauth2' => ['books:read']]], $this->security($document, '/books/{id}', 'get'));
    }

    #[Test]
    public function aDefaultNotNamingTheSchemeGainsItAsAnAlternative(): void
    {
        $document = $this->project($this->scoped(default: [SecurityRequirement::scheme('bearer')]));

        self::assertSame([['bearer' => []], ['oauth2' => ['books:read']]], $this->security($document, '/books/{id}', 'get'));
    }

    #[Test]
    public function anOperationNeedingNoScopeKeepsItsSecurity(): void
    {
        $document = $this->project($this->scoped(default: [SecurityRequirement::scheme('oauth2')]));

        self::assertSame([], $this->security($document, '/books', 'get'));
        self::assertNull($this->security($document, '/books/{id}', 'delete'));
    }

    #[Test]
    public function aScopedOperationOnAnUnsecuredServerAdvertisesA401(): void
    {
        $document = $this->project($this->scoped(default: []));

        self::assertSame([['oauth2' => ['books:read']]], $this->security($document, '/books/{id}', 'get'));
        $responses = self::dig($document, 'paths', '/books/{id}', 'get', 'responses');
        self::assertIsArray($responses);
        self::assertArrayHasKey('401', $responses);
    }

    #[Test]
    public function theAtomicEndpointNamesItsScopes(): void
    {
        $document = $this->project($this->scoped(default: [SecurityRequirement::scheme('oauth2')], atomic: true));

        self::assertSame([['oauth2' => ['batch']]], $this->security($document, '/operations', 'post'));
    }

    #[Test]
    public function theFlowsListTheCatalogueAfterTheConfiguredScopes(): void
    {
        $document = $this->project($this->scoped());

        self::assertSame(
            ['books:read' => 'Configured.', 'books:write' => 'Write books.', 'unused' => 'Not required anywhere.'],
            self::dig($document, 'components', 'securitySchemes', 'oauth2', 'flows', 'clientCredentials', 'scopes'),
        );
    }

    #[Test]
    public function aScopeNothingDeclaresIsADiagnostic(): void
    {
        $projection = $this->projection($this->scoped(catalog: []));

        self::assertEquals([new UndeclaredScope('books:write', ['update.books'])], $projection->undeclaredScopes);
        self::assertSame('The scope "books:write" is required by update.books but is not declared in the scope catalogue or the scope scheme\'s flows.', (string) $projection->undeclaredScopes[0]);
    }

    #[Test]
    public function aSchemeOtherThanOAuth2IsADiagnosticAndStillCarriesTheScopes(): void
    {
        $projection = $this->projection($this->scoped(default: [SecurityRequirement::scheme('bearer')], scheme: 'bearer'));

        self::assertEquals(new ScopeSchemeNotOAuth2('bearer', SecuritySchemeType::Http), $projection->scopeScheme);
        self::assertSame([['bearer' => ['books:read']]], $this->security($projection->document->toArray(), '/books/{id}', 'get'));
        self::assertNull($this->projection($this->scoped())->scopeScheme);
    }

    #[Test]
    public function aMissingSchemeThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Operation scopes are listed under the security scheme "nope", which the server does not define. Define it, or name one of: bearer, oauth2.');

        $this->project($this->scoped(scheme: 'nope'));
    }

    #[Test]
    public function aTableMissingAProjectedOperationThrows(): void
    {
        $server = new ScopeDeclaringServerMetadata($this->server(), new ScopeTable(['books' => ['fetchOne' => ['books:read']]]), schemes: $this->schemes());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The scope table has no entry for the operation "fetchCollection" of type "books".');

        $this->project($server);
    }

    #[Test]
    public function onlyADocumentWithScopesCarriesTheScopesContractAndError(): void
    {
        $scoped = $this->project($this->scoped());
        $plain = $this->project($this->server());

        self::assertSame(['contract' => GeneratorContract::SCOPES], self::dig($scoped, 'info', 'x-generator'));
        self::assertNotSame(['contract' => GeneratorContract::SCOPES], self::dig($plain, 'info', 'x-generator'));
        $scopedSchemas = self::dig($scoped, 'components', 'schemas');
        self::assertIsArray($scopedSchemas);
        self::assertArrayHasKey('InsufficientScopeError', $scopedSchemas);
        $plainSchemas = self::dig($plain, 'components', 'schemas');
        self::assertIsArray($plainSchemas);
        self::assertArrayNotHasKey('InsufficientScopeError', $plainSchemas);
    }

    /**
     * @param list<SecurityRequirement>|null $default
     * @param array<string, string>          $catalog
     */
    private function scoped(
        ?array $default = null,
        string $scheme = 'oauth2',
        array $catalog = ['books:write' => 'Write books.', 'books:read' => 'Catalogued.', 'unused' => 'Not required anywhere.'],
        bool $atomic = false,
    ): ServerMetadataInterface {
        $server = $this->server($default ?? [SecurityRequirement::scheme('oauth2')], $atomic);
        $rule = static fn(string $type, OperationKey $operation): array => match ($operation->kind) {
            OperationKind::FetchOne => ['books:read'],
            OperationKind::Update => ['books:write'],
            default => [],
        };

        return new ScopeDeclaringServerMetadata($server, ScopeTables::of($server, $rule, ['batch']), $scheme, $this->schemes(), $catalog);
    }

    /**
     * @return array<string, SecurityScheme>
     */
    private function schemes(): array
    {
        return ['oauth2' => SecurityScheme::oauth2(new OAuthFlows(
            clientCredentials: new OAuthFlow(['books:read' => 'Configured.'], tokenUrl: 'https://auth.example.com/token'),
        ))];
    }

    /**
     * @param list<SecurityRequirement>|null $default
     */
    private function server(?array $default = null, bool $atomic = false): FakeServerMetadata
    {
        $default ??= [SecurityRequirement::scheme('oauth2')];

        return new FakeServerMetadata(
            title: 'API',
            version: '1',
            types: [FakeTypeMetadata::resource(
                type: 'books',
                fields: [Id::make()->example('1')->build()],
                publicOperations: [OperationType::FetchCollection],
            )],
            securitySchemes: ['bearer' => SecurityScheme::bearer()],
            defaultSecurity: $default,
            atomicOperations: $atomic ? new FakeAtomicOperationsMetadata() : null,
            generatesExamples: false,
        );
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return list<array<string, list<string>>>|null
     */
    private function security(array $document, string $path, string $method): ?array
    {
        $operation = self::dig($document, 'paths', $path, $method);
        self::assertIsArray($operation, $path . ' ' . $method);

        /** @var list<array<string, list<string>>>|null */
        return $operation['security'] ?? null;
    }

    private static function dig(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    private function projection(ServerMetadataInterface $server): Projection
    {
        return (new OpenApiProjector())->projectWithDiagnostics($server);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(ServerMetadataInterface $server): array
    {
        /** @var array<array-key, mixed> */
        return \json_decode((string) \json_encode($this->projection($server)->document), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function json(ServerMetadataInterface $server): string
    {
        return (string) \json_encode($this->projection($server)->document);
    }
}
