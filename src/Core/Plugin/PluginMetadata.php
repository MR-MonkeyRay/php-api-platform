<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use JsonException;

final readonly class PluginMetadata
{
    private const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const VERSION_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $mainClass,
    ) {
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new InvalidPluginException(sprintf('plugin.json not found: %s', $file));
        }

        $json = file_get_contents($file);
        if ($json === false) {
            throw new InvalidPluginException(sprintf('Unable to read plugin.json: %s', $file));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidPluginException(
                sprintf('Invalid JSON in plugin metadata file: %s', $file),
                previous: $exception,
            );
        }

        if (!is_array($data)) {
            throw new InvalidPluginException('plugin.json root must be an object.');
        }

        return self::fromArray($data, $file);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $source = 'plugin.json'): self
    {
        foreach (['id', 'name', 'version', 'mainClass'] as $requiredField) {
            if (!array_key_exists($requiredField, $data) || trim((string) $data[$requiredField]) === '') {
                throw new InvalidPluginException(sprintf('Missing required field "%s" in %s', $requiredField, $source));
            }
        }

        $id = trim((string) $data['id']);
        $name = trim((string) $data['name']);
        $version = trim((string) $data['version']);
        $mainClass = trim((string) $data['mainClass']);

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidPluginException(
                sprintf('Invalid plugin id "%s" in %s. Expect lowercase letters, numbers and hyphen.', $id, $source)
            );
        }

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new InvalidPluginException(
                sprintf('Invalid plugin version "%s" in %s. Expect semver format.', $version, $source)
            );
        }

        return new self(
            id: $id,
            name: $name,
            version: $version,
            mainClass: $mainClass,
        );
    }
}
