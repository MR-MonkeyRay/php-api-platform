<?php

declare(strict_types=1);

namespace App\Core\Plugin\Installer;

final class GitRepositoryValidator
{
    private const GITHUB_PATTERN = '#^https://github\.com/(?P<owner>[A-Za-z0-9_.-]+)/(?P<repo>[A-Za-z0-9_.-]+?)(?:\.git)?$#';

    /**
     * @param list<string> $whitelistPatterns
     */
    public function __construct(
        private readonly array $whitelistPatterns = [],
        private readonly bool $enforceWhitelist = true,
    ) {
    }

    public function validate(string $repositoryUrl, string $ref): ValidationResult
    {
        $repositoryUrl = trim($repositoryUrl);
        $ref = trim($ref);

        if ($repositoryUrl === '') {
            return ValidationResult::invalid('Repository URL is required.');
        }

        if (!preg_match(self::GITHUB_PATTERN, $repositoryUrl, $matches)) {
            return ValidationResult::invalid('Only GitHub HTTPS repository URLs are allowed.');
        }

        $owner = (string) ($matches['owner'] ?? '');
        $repo = (string) ($matches['repo'] ?? '');
        if ($owner === '' || $repo === '') {
            return ValidationResult::invalid('Repository URL is invalid.');
        }

        if ($ref === '') {
            return ValidationResult::invalid('ref is required.');
        }

        if ($this->isFloatingBranch($ref)) {
            return ValidationResult::invalid('Floating branch refs are forbidden (master/main/latest/head).');
        }

        if (!$this->isAllowedRef($ref)) {
            return ValidationResult::invalid(
                'ref must be a fixed semver tag (vX.Y.Z) or full commit hash (40 hex chars).',
            );
        }

        if ($this->enforceWhitelist && !$this->isWhitelisted($owner, $repo)) {
            return ValidationResult::invalid('Repository is not in whitelist.');
        }

        return ValidationResult::valid($this->canonicalRepositoryUrl($owner, $repo));
    }

    private function isFloatingBranch(string $ref): bool
    {
        $normalized = strtolower($ref);

        return in_array($normalized, ['master', 'main', 'head', 'latest'], true);
    }

    private function isAllowedRef(string $ref): bool
    {
        return preg_match('/^v\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $ref) === 1
            || preg_match('/^[a-f0-9]{40}$/', $ref) === 1;
    }

    private function canonicalRepositoryUrl(string $owner, string $repo): string
    {
        return sprintf('https://github.com/%s/%s', $owner, $repo);
    }

    private function isWhitelisted(string $owner, string $repo): bool
    {
        if ($this->whitelistPatterns === []) {
            return false;
        }

        $target = strtolower($owner . '/' . $repo);

        foreach ($this->whitelistPatterns as $pattern) {
            $pattern = trim(strtolower($pattern));
            if ($pattern === '') {
                continue;
            }

            if ($this->matchesPattern($target, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $target, string $pattern): bool
    {
        if (str_starts_with($pattern, 'https://github.com/')) {
            $pattern = substr($pattern, strlen('https://github.com/')) ?: '';
        }

        $pattern = trim($pattern, '/');
        if ($pattern === '') {
            return false;
        }

        $regex = '#^' . str_replace('\\*', '[^/]+', preg_quote($pattern, '#')) . '$#i';

        return preg_match($regex, $target) === 1;
    }
}
