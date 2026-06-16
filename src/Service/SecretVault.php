<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;

final class SecretVault
{
    public function __construct(private Config $config)
    {
    }

    public function encrypt(string $plainText): string
    {
        $key = $this->keyBytes();

        if (extension_loaded('sodium')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plainText, $nonce, $key);

            return json_encode([
                'driver' => 'sodium',
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Nie udało się zaszyfrować danych.');
        }

        return json_encode([
            'driver' => 'openssl',
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public function decrypt(string $cipherText): string
    {
        $payload = json_decode($cipherText, true);
        if (!is_array($payload) || !isset($payload['driver'], $payload['nonce'], $payload['ciphertext'])) {
            throw new \RuntimeException('Nieprawidłowy format zaszyfrowanej wartości.');
        }

        $key = $this->keyBytes();
        $nonce = base64_decode((string) $payload['nonce'], true);
        $cipherBinary = base64_decode((string) $payload['ciphertext'], true);

        if ($nonce === false || $cipherBinary === false) {
            throw new \RuntimeException('Nieprawidłowy zapis danych zaszyfrowanych.');
        }

        if ($payload['driver'] === 'sodium') {
            $plainText = sodium_crypto_secretbox_open($cipherBinary, $nonce, $key);
            if ($plainText === false) {
                throw new \RuntimeException('Nie udało się odszyfrować danych.');
            }

            return $plainText;
        }

        $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
        if ($tag === false) {
            throw new \RuntimeException('Brakuje tagu GCM do odszyfrowania danych.');
        }

        $plainText = openssl_decrypt($cipherBinary, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plainText === false) {
            throw new \RuntimeException('Nie udało się odszyfrować danych.');
        }

        return $plainText;
    }

    private function keyBytes(): string
    {
        $key = (string) $this->config->get('app.app_encryption_key', '');
        if (mb_strlen($key) < 32) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY musi mieć co najmniej 32 znaki.');
        }

        return hash('sha256', $key, true);
    }
}
