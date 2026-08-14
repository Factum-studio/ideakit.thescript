<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\RegenerateAuthKeyCommand;
use core\application\port\IUserRepository;
use core\application\port\ISecurityService;
use core\domain\valueObject\UserId;
use RuntimeException;

class RegenerateAuthKeyHandler
{
    private IUserRepository $userRepository;
    private ISecurityService $securityService;

    public function __construct(
        IUserRepository $userRepository,
        ISecurityService $securityService,
    ) {
        $this->userRepository   = $userRepository;
        $this->securityService  = $securityService;
    }

    public function handle(RegenerateAuthKeyCommand $command): void
    {
        $user = $this->userRepository->findById(new UserId($command->userId));
        if (!$user) {
            throw new RuntimeException('User not found');
        }

        $newAuthKey = $this->securityService->generateRandomString(32);
        $user->changeAuthKey($newAuthKey);

        $this->userRepository->save($user);
    }
}
