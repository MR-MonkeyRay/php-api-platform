<?php

declare(strict_types=1);

namespace App\Core\Plugin;

final readonly class ApiDefinition
{
    public string $apiId;
    public string $visibilityDefault;

    /**
     * @var list<string>
     */
    public array $requiredScopesDefault;

    /**
     * @param list<string> $requiredScopesDefault
     */
    public function __construct(
        string $apiId,
        string $visibilityDefault = 'private',
        array $requiredScopesDefault = [],
    ) {
        $apiId = trim($apiId);
        if ($apiId === '') {
            throw new InvalidPluginException('apiId is required.');
        }

        $visibilityDefault = strtolower(trim($visibilityDefault));
        if (!in_array($visibilityDefault, ['public', 'private'], true)) {
            throw new InvalidPluginException('visibilityDefault must be "public" or "private".');
        }

        $normalizedScopes = [];
        foreach ($requiredScopesDefault as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                throw new InvalidPluginException('requiredScopesDefault must not contain empty scope.');
            }

            $normalizedScopes[] = $scope;
        }

        $this->apiId = $apiId;
        $this->visibilityDefault = $visibilityDefault;
        $this->requiredScopesDefault = array_values(array_unique($normalizedScopes));
    }
}
