<?php

declare(strict_types=1);

namespace core\security;

use core\application\port\IJwtManager;
use core\application\port\IUserRepository;
use core\domain\valueObject\UserId;
use Yii;
use yii\base\ActionFilter;
use yii\web\UnauthorizedHttpException;
use core\infrastructure\jwt\JwtManager;

class JwtMiddleware extends ActionFilter
{
    private JwtManager $jwtManager;
    private IUserRepository $userRepository;

    public function __construct(
        IJwtManager $jwtManager,
        IUserRepository $userRepository,
        $config = []
    ) {
        $this->jwtManager       = $jwtManager;
        $this->userRepository   = $userRepository;
        parent::__construct($config);
    }

    /**
     * @throws UnauthorizedHttpException
     */
    public function handle(): void
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

        if (!$token) {
            throw new UnauthorizedHttpException('Token not found.');
        }

        $payload = $this->jwtManager->validate($token);
        if (!$payload) {
            throw new UnauthorizedHttpException('Invalid or expired token.');
        }

        $user = $this->userRepository->findById(new UserId($payload->userId));
        if (!$user || !$user->getStatus()->isActive()) {
            throw new UnauthorizedHttpException('Account is inactive or not found.');
        }

        // Проверяем соответствие auth_key
        if ($payload->authKey && $user->getAuthKey() !== $payload->authKey) {
            throw new UnauthorizedHttpException('Token revoked. Please re-authenticate.');
        }

        $identity = new YiiIdentity($user);
        Yii::$app->user->setIdentity($identity);
    }
}
