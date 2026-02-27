<?php

declare(strict_types=1);

namespace App\Core\ApiKey;

final class ApiKeyGenerator
{
    /**
     * @return array{kid:string,secret:string,full_key:string}
     */
    public function generate(): array
    {
        $kid = $this->generateKid();
        $secret = $this->generateSecret();

        return [
            'kid' => $kid,
            'secret' => $secret,
            'full_key' => sprintf('%s.%s', $kid, $secret),
        ];
    }

    public function generateKid(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function generateSecret(): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return $secret;
    }
}

