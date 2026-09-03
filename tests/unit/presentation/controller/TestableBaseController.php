<?php

namespace unit\presentation\controller;

use core\application\dto\CollectionDto;
use core\application\dto\ErrorDto;
use core\application\dto\ItemDto;
use core\application\dto\SuccessDto;
use core\domain\valueObject\IdRange;
use core\presentation\controller\BaseController;

class TestableBaseController extends BaseController
{
    /**
     * @var mixed Заглушка для user->id
     */
    private mixed $stubUserId = null;

    /**
     * @var array Заглушка для request->get()
     */
    private array $stubRequestParams = [];

    public function publicItem($dto): ItemDto
    {
        return $this->item($dto);
    }

    public function publicCollection(array $items, ?int $total = null, ?int $page = null, ?int $limit = null): CollectionDto
    {
        return $this->collection($items, $total, $page, $limit);
    }

    public function publicError(string $message, int $code = 400, array $details = []): ErrorDto
    {
        return $this->error($message, $code, $details);
    }

    public function publicSuccess($data = null, string $message = 'OK'): SuccessDto
    {
        return $this->success($data, $message);
    }

    public function publicParseIdRangeFromPath(?string $param): ?IdRange
    {
        return $this->parseIdRangeFromPath($param);
    }

    /* --------------/ Полное переопределение базовых методов /-------------- */
    public function getUserId(): ?string
    {
        return $this->stubUserId;
    }

    public function getLimit(): int
    {
        $limit = $this->stubRequestParams['limit'] ?? $this->defaultPageSize;
        return max($this->pageSizeLimit[0], min((int)$limit, $this->pageSizeLimit[1]));
    }

    public function getPage(): int
    {
        $page = $this->stubRequestParams['page'] ?? 1;
        return max(1, (int)$page);
    }
    /* --------------/ Полное переопределение базовых методов /-------------- */

    public function publicGetUserId(): ?int
    {
        return $this->getUserId();
    }

    public function publicGetLimit(): int
    {
        return $this->getLimit();
    }

    public function publicGetPage(): int
    {
        return $this->getPage();
    }

    /**
     * Методы для управления заглушками в тестах
     */
    public function setStubUserId($userId): void
    {
        $this->stubUserId = $userId;
    }

    public function setStubRequestParam(string $key, $value): void
    {
        $this->stubRequestParams[$key] = $value;
    }

    public function setStubRequestParams(array $params): void
    {
        $this->stubRequestParams = $params;
    }

    public function resetStubs(): void
    {
        $this->stubUserId = null;
        $this->stubRequestParams = [];
    }
}