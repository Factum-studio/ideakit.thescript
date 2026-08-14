<?php

declare(strict_types=1);

namespace core\application\port;

interface ISecurityService
{
    /**
     * Генерирует криптографически безопасную случайную строку.
     *
     * @param int $length Длина строки в байтах (результат может быть длиннее в hex/ base64)
     * @return string
     */
    public function generateRandomString(int $length = 32): string;
}
