<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin\Installer;

use App\Core\Plugin\Installer\GitRepositoryValidator;
use PHPUnit\Framework\TestCase;

final class GitRepositoryValidatorTest extends TestCase
{
    public function testValidGithubUrlWithTagPasses(): void
    {
        $validator = new GitRepositoryValidator(['trusted/repo']);

        $result = $validator->validate('https://github.com/trusted/repo', 'v1.2.3');

        self::assertTrue($result->valid);
        self::assertSame('https://github.com/trusted/repo', $result->canonicalRepositoryUrl);
        self::assertNull($result->error);
    }

    public function testRejectsMasterBranch(): void
    {
        $validator = new GitRepositoryValidator(['trusted/repo']);

        $result = $validator->validate('https://github.com/trusted/repo', 'master');

        self::assertFalse($result->valid);
        self::assertNotNull($result->error);
        self::assertStringContainsString('forbidden', strtolower((string) $result->error));
    }

    public function testRejectsMainBranch(): void
    {
        $validator = new GitRepositoryValidator(['trusted/repo']);

        $result = $validator->validate('https://github.com/trusted/repo', 'main');

        self::assertFalse($result->valid);
    }

    public function testRejectsInvalidTagFormat(): void
    {
        $validator = new GitRepositoryValidator(['trusted/repo']);

        $result = $validator->validate('https://github.com/trusted/repo', '1.2.3');

        self::assertFalse($result->valid);
        self::assertStringContainsString('semver', strtolower((string) $result->error));
    }

    public function testAcceptsFullCommitHash(): void
    {
        $validator = new GitRepositoryValidator(['trusted/repo']);
        $hash = str_repeat('a', 40);

        $result = $validator->validate('https://github.com/trusted/repo.git', $hash);

        self::assertTrue($result->valid);
        self::assertSame('https://github.com/trusted/repo', $result->canonicalRepositoryUrl);
    }

    public function testEnforcesWhitelist(): void
    {
        $validator = new GitRepositoryValidator(['trusted/*'], enforceWhitelist: true);

        $result = $validator->validate('https://github.com/untrusted/plugin', 'v1.0.0');

        self::assertFalse($result->valid);
        self::assertStringContainsString('whitelist', strtolower((string) $result->error));
    }

    public function testAllowsWildcardWhitelistPattern(): void
    {
        $validator = new GitRepositoryValidator(['https://github.com/trusted/*'], enforceWhitelist: true);

        $result = $validator->validate('https://github.com/trusted/plugin-x', 'v1.0.0');

        self::assertTrue($result->valid);
    }

    public function testRejectsNonGithubRepositoryUrl(): void
    {
        $validator = new GitRepositoryValidator(['trusted/repo']);

        $result = $validator->validate('https://gitlab.com/trusted/repo', 'v1.0.0');

        self::assertFalse($result->valid);
        self::assertStringContainsString('github', strtolower((string) $result->error));
    }
}
