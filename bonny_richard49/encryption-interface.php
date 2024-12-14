<?php

namespace App\Core\Interfaces;

interface EncryptionServiceInterface
{
    public function encrypt(string $data, ?string $key = null): string;
    public function decrypt(string $data, ?string $key = null): string;
    public function hash(string $data, string $salt = ''): string;
    public function verify(string $data, string $hash, string $salt = ''): bool;
    public function generateKey(): string;
}
