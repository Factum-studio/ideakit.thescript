# Базовый контроллер (BaseController)
`BaseController` наследуется от `yii\rest\Controller` (или `yii\web\Controller`, но в шаблоне используется `rest\Controller`). Он предоставляет набор защищённых методов для упрощения формирования ответов.

## Основные методы
```php
protected function item($dto): ItemDto
```
Оборачивает переданные данные в `ItemDto`.

```php
protected function collection(array $items, ?int $total = null, ?int $page = null, ?int $limit = null): CollectionDto
```
Создаёт `CollectionDto` с возможностью передачи пагинации.

```php
protected function error(string $message, int $code = 400, array $details = []): ErrorDto
```
Генерирует `ErrorDto` для ответа об ошибке.

```php
protected function success($data = null, string $message = 'OK'): SuccessDto
```
Возвращает `SuccessDto`.

```php
protected function parseIdRangeFromPath(?string $param): ?IdRange
```
Парсит строку с диапазонами ID (например, `1,3:20`) в объект `IdRange` (value object из домена).

```php
protected function getUserId(): ?int
```
Возвращает ID текущего пользователя из `Yii::$app->user->id`.

```php
protected function getUserIdentity(): ?YiiIdentity
```
Возвращает объект `YiiIdentity` текущего пользователя.

```php
protected function getLimit(): int
```
Извлекает параметр `limit` из запроса, ограничивая его заданными пределами (`pageSizeLimit`).

```php
protected function getPage(): int
```
Извлекает параметр `page` (по умолчанию 1).

## Использование в дочерних контроллерах

```php
public function actionIndex()
{
    $items = ...; // массив данных
    return $this->collection($items, 100, 1, 20);
}

public function actionCreate()
{
    // ...
    return $this->success(['id' => 123], 'Created');
}
```
