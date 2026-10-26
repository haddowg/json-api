<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs `bin/contract-guard.php` in a throwaway repository with one release tag, so each
 * case compares a rewritten witness against a known released one at an unchanged contract.
 */
#[CoversNothing]
final class ContractGuardTest extends TestCase
{
    private const WITNESS = 'tests/OpenApi/Fixture/contract-witness.json';

    private const BASELINE_WITNESS = 'tests/OpenApi/Fixture/contract-witness-baseline.json';

    private string $repo = '';

    protected function setUp(): void
    {
        $repo = \sys_get_temp_dir() . '/contract-guard-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($repo . '/bin', 0o777, true));
        self::assertTrue(\mkdir($repo . '/src/OpenApi', 0o777, true));
        self::assertTrue(\mkdir($repo . '/tests/OpenApi/Fixture', 0o777, true));
        $this->repo = (string) \realpath($repo);

        \copy(\dirname(__DIR__, 2) . '/bin/contract-guard.php', $this->repo . '/bin/contract-guard.php');
        \file_put_contents($this->repo . '/src/OpenApi/GeneratorContract.php', "<?php\nfinal class GeneratorContract { public const CONTRACT = 3; }\n");
        $this->writeWitness(self::link());

        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'release');
        $this->git('tag', 'v1.0.0');
    }

    protected function tearDown(): void
    {
        if ($this->repo === '' || !\str_starts_with($this->repo, (string) \realpath(\sys_get_temp_dir()) . '/contract-guard-')) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repo, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() && !$entry->isLink() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }
        \rmdir($this->repo);
    }

    #[Test]
    public function aChangedExampleOrDescriptionIsNotStructure(): void
    {
        $link = self::link();
        $link['example'] = ['href' => 'https://api.example.com/articles/1'];
        $link['properties']['href']['example'] = '/articles/1';
        $link['description'] = 'Reworded.';
        $this->writeWitness($link);

        [$status, $output] = $this->guard();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('unchanged since v1.0.0', $output);
    }

    #[Test]
    public function aChangedMediaTypeExampleIsNotStructure(): void
    {
        $this->release(['data' => ['type' => 'articles', 'id' => '1']]);
        $this->writeWitness(self::link(), response: ['data' => ['type' => 'articles', 'id' => '2']]);

        [$status, $output] = $this->guard();

        self::assertSame(0, $status, $output);
    }

    #[Test]
    public function aMediaTypeGainingAnExampleIsStructure(): void
    {
        $this->release(null);
        $this->writeWitness(self::link(), response: ['data' => ['type' => 'articles', 'id' => '1']]);

        [$status, $output] = $this->guard();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('Bump GeneratorContract::CONTRACT to 4', $output);
    }

    #[Test]
    public function aStructuralChangeStillFails(): void
    {
        $link = self::link();
        $link['required'] = [];
        $this->writeWitness($link);

        [$status, $output] = $this->guard();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('Bump GeneratorContract::CONTRACT to 4', $output);
    }

    #[Test]
    public function aMemberNamedExampleIsStructure(): void
    {
        $link = self::link();
        $link['properties']['example']['type'] = 'integer';
        $this->writeWitness($link);

        [$status, $output] = $this->guard();

        self::assertSame(1, $status, $output);
    }

    #[Test]
    public function aMappingKeyNamedDescriptionIsStructure(): void
    {
        $link = self::link();
        $link['discriminator']['mapping']['description'] = '#/components/schemas/Other';
        $this->writeWitness($link);

        [$status, $output] = $this->guard();

        self::assertSame(1, $status, $output);
    }

    #[Test]
    public function aBumpScopedToSomeDocumentsPassesWhileTheSharedShapeHolds(): void
    {
        $link = self::link();
        $link['required'] = [];
        $this->writeWitness($link);
        $this->writeWitness(self::link(), self::BASELINE_WITNESS);
        $this->writeContract(4, baseline: 3);

        [$status, $output] = $this->guard();

        self::assertSame(0, $status, $output);
    }

    #[Test]
    public function aChangeToTheSharedShapeCannotHideBehindAScopedBump(): void
    {
        $link = self::link();
        $link['required'] = [];
        $this->writeWitness($link);
        $this->writeWitness($link, self::BASELINE_WITNESS);
        $this->writeContract(4, baseline: 3);

        [$status, $output] = $this->guard();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('cannot be scoped', $output);
    }

    #[Test]
    public function aChangeToTheSharedShapePassesOnceTheBaselineMovesWithIt(): void
    {
        $link = self::link();
        $link['required'] = [];
        $this->writeWitness($link);
        $this->writeWitness($link, self::BASELINE_WITNESS);
        $this->writeContract(4, baseline: 4);

        [$status, $output] = $this->guard();

        self::assertSame(0, $status, $output);
    }

    #[Test]
    public function aBaselineBumpWithNoSharedChangeFails(): void
    {
        $link = self::link();
        $link['required'] = [];
        $this->writeWitness($link);
        $this->writeWitness(self::link(), self::BASELINE_WITNESS);
        $this->writeContract(4, baseline: 4);

        [$status, $output] = $this->guard();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('Reset BASELINE to 3', $output);
    }

    private function writeContract(int $contract, int $baseline): void
    {
        \file_put_contents(
            $this->repo . '/src/OpenApi/GeneratorContract.php',
            "<?php\nfinal class GeneratorContract { public const CONTRACT = {$contract}; public const BASELINE = {$baseline}; }\n",
        );
    }

    /**
     * The one schema the released witness carries.
     *
     * @return array{type: string, description: string, example: array<string, string>, properties: array{href: array<string, string>, example: array<string, string>}, required: list<string>, discriminator: array{propertyName: string, mapping: array<string, string>}}
     */
    private static function link(): array
    {
        return [
            'type' => 'object',
            'description' => 'A link.',
            'example' => ['href' => 'https://api.example.com/'],
            'properties' => [
                'href' => ['type' => 'string', 'example' => '/'],
                'example' => ['type' => 'string'],
            ],
            'required' => ['href'],
            'discriminator' => [
                'propertyName' => 'type',
                'mapping' => ['description' => '#/components/schemas/Link'],
            ],
        ];
    }

    /**
     * Re-releases the witness as a second tag, with a response whose media type carries
     * `$example` (or no example when `null`).
     */
    private function release(mixed $example): void
    {
        $this->writeWitness(self::link(), response: $example);
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'release 1.0.1');
        $this->git('tag', 'v1.0.1');
    }

    /**
     * @param array<string, mixed> $link
     * @param mixed                $response the example on a response's media type; `false` for no response
     */
    private function writeWitness(array $link, string $path = self::WITNESS, mixed $response = false): void
    {
        $components = ['schemas' => ['Link' => $link]];
        if ($response !== false) {
            $media = ['schema' => ['$ref' => '#/components/schemas/Link']];
            if ($response !== null) {
                $media['example'] = $response;
            }
            $components['responses'] = ['Ok' => ['description' => 'OK', 'content' => ['application/vnd.api+json' => $media]]];
        }
        $witness = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Witness', 'version' => '1', 'x-generator' => ['contract' => 3]],
            'components' => $components,
        ];
        \file_put_contents($this->repo . '/' . $path, \json_encode($witness, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Runs git with no user or system configuration, so local hooks and signing never apply.
     */
    private function git(string ...$args): void
    {
        $command = self::isolated() . 'git -C ' . \escapeshellarg($this->repo)
            . ' -c user.name=guard -c user.email=guard@example.com -c commit.gpgsign=false -c tag.gpgsign=false';
        foreach ($args as $arg) {
            $command .= ' ' . \escapeshellarg($arg);
        }

        $output = [];
        $status = 0;
        \exec($command . ' 2>&1', $output, $status);
        self::assertSame(0, $status, \implode("\n", $output));
    }

    /**
     * @return array{int, string}
     */
    private function guard(): array
    {
        $output = [];
        $status = 0;
        \exec(self::isolated() . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($this->repo . '/bin/contract-guard.php') . ' 2>&1', $output, $status);

        return [$status, \implode("\n", $output)];
    }

    private static function isolated(): string
    {
        return 'GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 ';
    }
}
