<?php

declare(strict_types=1);

namespace core\security;

use core\application\port\IUserRepository;
use core\domain\valueObject\UserId;
use Yii;
use yii\base\ActionFilter;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\UnauthorizedHttpException;
use core\infrastructure\jwt\JwtManager;

class JwtMiddleware extends ActionFilter
{
    private JwtManager $jwtManager;

    public function __construct(JwtManager $jwtManager, $config = [])
    {
        $this->jwtManager = $jwtManager;
        parent::__construct($config);
    }

    /**
     * @throws NotInstantiableException
     * @throws InvalidConfigException
     * @throws UnauthorizedHttpException
     */
    public function beforeAction($action): bool
    {
        $request    = Yii::$app->request;
        $token      = null;

        // Из заголовка Authorization: Bearer <token>
        $authHeader = $request->getHeaders()->get('Authorization');
        if ($authHeader && preg_match('/^Bearer\s+(.*?)$/', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // Или из cookie
        if (!$token) {
            $token = $request->getCookies()->getValue('access_token');
        }

        if ($token) {
            $payload = $this->jwtManager->validate($token);
            if (!$payload) {
                throw new UnauthorizedHttpException('Invalid or expired token.');
            }

            /** @var IUserRepository $userRepo */
            $userRepo = Yii::$container->get(IUserRepository::class);
            $user = $userRepo->findById(new UserId($payload->userId));
            if (!$user || !$user->getStatus()->isActive()) {
                throw new UnauthorizedHttpException('Account is inactive or not found.');
            }

            $identity = new YiiIdentity($user);
            Yii::$app->user->setIdentity($identity);
        }

        return parent::beforeAction($action);
    }
}
