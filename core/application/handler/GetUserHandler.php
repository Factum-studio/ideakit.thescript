<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\query\GetUserQuery;
use core\application\port\IUserRepository;
use core\application\port\IUserIdentityRepository;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use RuntimeException;
use core\application\dto\UserDto;

class GetUserHandler
{
    private IUserRepository $userRepository;
    private IUserIdentityRepository $identityRepository;

    public function __construct(
        IUserRepository $userRepository,
        IUserIdentityRepository $identityRepository,
    ) {
        $this->userRepository       = $userRepository;
        $this->identityRepository   = $identityRepository;
    }

    /**
     * @throws UserNotFoundException
     */
    public function handle(GetUserQuery $query): UserDto
    {
        $user = $this->userRepository->findById(new UserId($query->userId));
        if (!$user) {
            throw new UserNotFoundException("User with ID {$query->userId} not found");
        }

        $identities = [];
        if (in_array('identities', $query->expand, true)) {
            $identities = $this->identityRepository->findByUserId($user->getId());
        }

        return new UserDto($user, $identities);
    }
}
