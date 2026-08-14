<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\query\GetUserByIdentityQuery;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\application\dto\UserDto;
use core\domain\exception\IdentityNotFoundException;
use core\domain\exception\UserNotFoundException;
use RuntimeException;

class GetUserByIdentityHandler
{
    private IUserIdentityRepository $identityRepository;
    private IUserRepository $userRepository;

    public function __construct(
        IUserIdentityRepository $identityRepository,
        IUserRepository $userRepository
    ) {
        $this->identityRepository   = $identityRepository;
        $this->userRepository       = $userRepository;
    }

    /**
     * @throws IdentityNotFoundException
     * @throws UserNotFoundException
     */
    public function handle(GetUserByIdentityQuery $query): UserDto
    {
        $identity = $this->identityRepository->findByProviderAndClientId(
            $query->provider,
            $query->providerClientId
        );
        if (!$identity) {
            throw new IdentityNotFoundException(
                "Identity not found for provider '{$query->provider}' and client ID '{$query->providerClientId}'"
            );
        }

        $user = $this->userRepository->findById($identity->getUserId());
        if (!$user) {
            throw new UserNotFoundException("User for identity not found");
        }

        return new UserDto($user);
    }
}
