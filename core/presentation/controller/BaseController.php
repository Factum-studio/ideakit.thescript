<?php

namespace core\presentation\controller;

use core\security\YiiIdentity;
use yii\rest\Controller;
use core\domain\valueObject\IdRange;

use core\application\dto\CollectionDto;
use core\application\dto\SuccessDto;
use core\application\dto\ItemDto;
use core\application\dto\ErrorDto;

abstract class BaseController extends Controller
{
    public $defaultPageSize = 20;
    public $pageSizeLimit = [1, 1000];

    protected function item($dto): ItemDto
    {
        return new ItemDto($dto);
    }

    protected function collection(array $items, ?int $total = null, ?int $page = null, ?int $limit = null): CollectionDto {
        return new CollectionDto($items, $total, $page, $limit);
    }

    protected function error(string $message, int $code = 400, array $details = []): ErrorDto
    {
        return new ErrorDto($message, $code, $details);
    }

    protected function success($data = null, string $message = 'OK'): SuccessDto
    {
        return new SuccessDto($data, $message);
    }

    protected function parseIdRangeFromPath(?string $param): ?IdRange
    {
        if ($param === null || trim($param) === '') return null;

        try {
            return IdRange::fromString($param);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getUserId(): ?int
    {
        return \Yii::$app->user->id ?? null;
    }

    protected function getUserIdentity(): ?YiiIdentity
    {
        return \Yii::$app->user->identity ?? null;
    }

    protected function getLimit(): int
    {
        $limit = (int)\Yii::$app->request->get('limit', $this->defaultPageSize);
        return max($this->pageSizeLimit[0], min($limit, $this->pageSizeLimit[1]));
    }

    protected function getPage(): int
    {
        return max(1, (int)\Yii::$app->request->get('page', 1));
    }
}