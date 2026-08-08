# Data Transfer Objects (DTO)

Все DTO расположены в `core/application/dto/` и реализуют интерфейс `JsonSerializable` для автоматического форматирования при выводе в JSON.

## CollectionDto
Используется для возврата пагинированных списков.
```php
class CollectionDto implements JsonSerializable
{
    public function __construct(
        public array $items,
        public ?int $total  = null,
        public ?int $page   = null,
        public ?int $limit  = null
    ) {}

    public function jsonSerialize(): array
    {
        $result = ['items' => $this->items];
        if ($this->total !== null) {
            $result['_meta'] = [
                'total' => $this->total,
                'page'  => $this->page,
                'limit' => $this->limit,
                'pages' => $this->limit ? ceil($this->total / $this->limit) : null
            ];
        }
        return $result;
    }
}
```
При сериализации добавляет блок `_meta` с информацией о пагинации, если передан `total`.

## ErrorDto
Стандартизированный формат ошибок:
```php
class ErrorDto implements JsonSerializable
{
    public function __construct(
        public string $message,
        public int $code        = 400,
        public array $details   = []
    ) {}

    public function jsonSerialize(): array
    {
        $result = [
            'error' => [
                'code'      => $this->code,
                'message'   => $this->message,
            ]
        ];
        if (!empty($this->details)) {
            $result['error']['details'] = $this->details;
        }
        return $result;
    }
}
```
Возвращает объект с ключом `error`, содержащим код, сообщение и необязательные детали.

## ItemDto
Обёртка для одного элемента:
```php
class ItemDto implements JsonSerializable
{
    public function __construct(public mixed $item) {}

    public function jsonSerialize(): array
    {
        return ['item' => $this->item];
    }
}
```

## SuccessDto
Для успешных ответов:
```php
class SuccessDto implements JsonSerializable
{
    public function __construct(
        public mixed $data      = null,
        public string $message  = 'OK'
    ) {}

    public function jsonSerialize(): array
    {
        if ($this->data === null) return ['message' => $this->message];
        if (is_array($this->data)) return $this->data;
        if (is_object($this->data) && method_exists($this->data, 'jsonSerialize'))
            return $this->data->jsonSerialize();
        return ['data' => $this->data];
    }
}
```
Если данные не переданы, возвращает только `message`. Если данные - массив или объект с `jsonSerialize()`, возвращает их напрямую; иначе оборачивает в ключ `data`.
