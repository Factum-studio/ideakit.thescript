<?php

declare(strict_types=1);

namespace core\application\dto;

use core\domain\entity\User;
use core\domain\entity\UserIdentity;
use JsonSerializable;

class UserDto implements JsonSerializable
{
    private User $user;

    /**
     * @var UserIdentity[]
     */
    private array $identities = [];

    public function __construct(User $user, array $identities = [])
    {
        $this->user         = $user;
        $this->identities   = $identities;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'id'        => $this->user->getId()->value(),
            'surname'   => $this->user->getSurname(),
            'name'      => $this->user->getName(),
            'patronymic' => $this->user->getPatronymic(),
            'email'     => $this->user->getEmail()?->value(),
            'phone'     => $this->user->getPhone()?->value(),
            'role'      => $this->user->getRole()->value(),
            'post'      => $this->user->getPost(),
            'status'    => $this->user->getStatus()->value(),
            'created_at' => $this->user->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $this->user->getUpdatedAt()->format('Y-m-d H:i:s'),
            'last_login_at' => $this->user->getLastLoginAt()?->format('Y-m-d H:i:s'),
        ];

        if (!empty($this->identities)) {
            $data['identities'] = array_map(function (UserIdentity $identity) {
                return [
                    'id'            => $identity->getId()->value(),
                    'provider'      => $identity->getProvider(),
                    'provider_client_id' => $identity->getProviderClientId(),
                    'created_at'    => $identity->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }, $this->identities);
        }

        return $data;
    }
}
