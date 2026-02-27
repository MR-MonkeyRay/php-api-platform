<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use Slim\App;

interface PluginInterface
{
    public function getId(): string;

    public function getName(): string;

    public function getVersion(): string;

    public function routes(App $app): void;

    /**
     * @return list<ApiDefinition>
     */
    public function apis(): array;
}
