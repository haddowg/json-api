<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Exception\ResponseBodyInvalidJsonApi;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class DocumentValidatorTest extends TestCase
{
    private function validator(): DocumentValidator
    {
        return new DocumentValidator(new VendoredSchemaProvider());
    }

    private function decodedFragment(string $json): object
    {
        $fragment = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertIsObject($fragment);

        return $fragment;
    }

    // ---- responses ----

    #[Test]
    public function validResponseDocumentPasses(): void
    {
        $this->validator()->validateResponse([
            'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'JSON:API']],
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function responseResourceMissingIdIsRejected(): void
    {
        try {
            $this->validator()->validateResponse(['data' => ['type' => 'articles']]);
            self::fail('Expected ResponseBodyInvalidJsonApi.');
        } catch (ResponseBodyInvalidJsonApi $exception) {
            self::assertSame(500, $exception->getStatusCode());
            self::assertContains('/data', $this->pointers($exception->validationErrors));
        }
    }

    #[Test]
    public function responseWithBothDataAndErrorsIsRejected(): void
    {
        $this->expectException(ResponseBodyInvalidJsonApi::class);

        $this->validator()->validateResponse([
            'data' => ['type' => 'articles', 'id' => '1'],
            'errors' => [['status' => '400']],
        ]);
    }

    #[Test]
    public function responseWithUnknownTopLevelMemberIsRejected(): void
    {
        $this->expectException(ResponseBodyInvalidJsonApi::class);

        $this->validator()->validateResponse([
            'data' => ['type' => 'articles', 'id' => '1'],
            'aggregations' => ['count' => 3],
        ]);
    }

    #[Test]
    public function pointerLocatesTheOffendingPrimaryData(): void
    {
        try {
            // `id` must be a string; the violation is located within /data. (opis 2.1+
            // drills to /data/id; 2.0 reports at the /data oneOf — /data is the stable,
            // spec-correct pointer asserted here.)
            $this->validator()->validateResponse([
                'data' => ['type' => 'articles', 'id' => 123],
            ]);
            self::fail('Expected ResponseBodyInvalidJsonApi.');
        } catch (ResponseBodyInvalidJsonApi $exception) {
            $pointers = $this->pointers($exception->validationErrors);
            self::assertNotEmpty($pointers);
            self::assertTrue(
                (bool) \array_filter($pointers, static fn(string $p): bool => \str_starts_with($p, '/data')),
                'Expected a violation pointer under /data, got: ' . \implode(', ', $pointers),
            );

            // The mapped Error objects carry the pointer as source.pointer.
            $sources = \array_filter(
                \array_map(static fn($error) => $error->source?->pointer, $exception->getErrors()),
            );
            self::assertContains('/data', $sources);
        }
    }

    // ---- requests ----

    #[Test]
    public function clientGeneratedResourceWithoutIdPassesRequestValidation(): void
    {
        $this->validator()->validateRequest([
            'data' => ['type' => 'articles', 'attributes' => ['title' => 'New']],
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function resourceWithLidPassesRequestValidation(): void
    {
        $this->validator()->validateRequest([
            'data' => ['type' => 'articles', 'lid' => 'tmp-1', 'attributes' => ['title' => 'New']],
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function requestResourceMissingTypeIsRejected(): void
    {
        try {
            $this->validator()->validateRequest(['data' => ['attributes' => ['title' => 'New']]]);
            self::fail('Expected RequestBodyInvalidJsonApi.');
        } catch (RequestBodyInvalidJsonApi $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertContains('/data', $this->pointers($exception->validationErrors));
        }
    }

    #[Test]
    public function requestMissingDataIsRejected(): void
    {
        $this->expectException(RequestBodyInvalidJsonApi::class);

        $this->validator()->validateRequest(['meta' => ['note' => 'no data']]);
    }

    #[Test]
    public function requestRelationshipLinkagePasses(): void
    {
        $this->validator()->validateRequest([
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'New'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'people', 'id' => '9']],
                ],
            ],
        ]);

        $this->expectNotToPerformAssertions();
    }

    // ---- profile fragment composition (allOf) ----

    #[Test]
    public function profileFragmentAcceptsTopLevelMemberBaseSchemaWouldReject(): void
    {
        $document = ['data' => ['type' => 'articles', 'id' => '1'], 'aggregations' => ['count' => 3]];
        $fragment = $this->decodedFragment('{"properties":{"aggregations":{"type":"object"}}}');

        // Base alone rejects the unknown top-level member...
        $rejected = false;
        try {
            $this->validator()->validateResponse($document);
        } catch (ResponseBodyInvalidJsonApi) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'Base schema should reject the unknown top-level member.');

        // ...but with the profile fragment composed in, it is accepted.
        $this->validator()->validateResponse($document, [$fragment]);
    }

    #[Test]
    public function profileFragmentStillEnforcesBaseSchema(): void
    {
        // A fragment relaxes, but base constraints (data XOR errors) still apply.
        $fragment = $this->decodedFragment('{"properties":{"aggregations":{"type":"object"}}}');

        $this->expectException(ResponseBodyInvalidJsonApi::class);

        $this->validator()->validateResponse(
            ['data' => ['type' => 'a', 'id' => '1'], 'errors' => [['status' => '400']], 'aggregations' => []],
            [$fragment],
        );
    }

    #[Test]
    public function additionalSchemaCanTightenValidation(): void
    {
        // A per-resource-style schema composing extra constraints: require the
        // article title to be a string.
        $schema = $this->decodedFragment(
            '{"properties":{"data":{"properties":{"attributes":{"required":["title"],"properties":{"title":{"type":"string"}}}}}}}',
        );

        $this->expectException(ResponseBodyInvalidJsonApi::class);

        $this->validator()->validateResponse(
            ['data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 123]]],
            [$schema],
        );
    }

    /**
     * @param list<array{message: string, property?: string}> $violations
     *
     * @return list<string>
     */
    private function pointers(array $violations): array
    {
        $pointers = [];
        foreach ($violations as $violation) {
            if (isset($violation['property'])) {
                $pointers[] = $violation['property'];
            }
        }

        return $pointers;
    }
}
