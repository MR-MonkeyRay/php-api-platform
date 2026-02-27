<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(
        STDERR,
        "[tests/bootstrap] vendor/autoload.php 不存在，请先运行 composer install。\n"
    );

    exit(1);
}

require $autoload;

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Tests\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
);
