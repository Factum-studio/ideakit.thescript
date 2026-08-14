<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\dto\UserFiltersDto;
use core\application\query\FindUserQuery;
use core\application\port\IUserRepository;
use core\application\dto\CollectionDto;
use core\application\dto\UserDto;

class FindUserHandler
{
    private IUserRepository $userRepository;

    public function __construct(IUserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function handle(FindUserQuery $query): CollectionDto
    {
        $filters = new UserFiltersDto(
            email: $query->email,
            phone: $query->phone,
            role: $query->role,
            post: $query->post,
            status: $query->status,
            createdFrom: $query->createdFrom,
            createdTo: $query->createdTo,
            updatedFrom: $query->updatedFrom,
            updatedTo: $query->updatedTo,
            lastLoginFrom: $query->lastLoginFrom,
            lastLoginTo: $query->lastLoginTo,
            limit: $query->limit,
            offset: ($query->page - 1) * $query->limit,
            orderBy: $query->orderBy ?? ['created_at' => SORT_DESC],
        );

        $result = $this->userRepository->findWithFilters($filters);

        $items = array_map(function ($user) {
            return new UserDto($user);
        }, $result['items']);

        return new CollectionDto($items, $result['total'], $query->page, $query->limit);
    }
}
