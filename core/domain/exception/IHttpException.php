<?php

declare(strict_types=1);

namespace core\domain\exception;

interface IHttpException
{
    public function getStatusCode(): int;
}
