<?php

declare(strict_types=1);

namespace core\infrastructure\security;

use core\application\port\ISecurityService;
use Yii;
use yii\base\Exception;

class YiiSecurityService implements ISecurityService
{
    /**
     * @throws Exception
     */
    public function generateRandomString(int $length = 32): string
    {
        return Yii::$app->security->generateRandomString($length);
    }
}
