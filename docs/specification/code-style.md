# Code Style
Данный документ описывает стандарты кодирования. Все новые модули и код должны соответствовать этим правилам для обеспечения единообразия, поддерживаемости и масштабируемости.

## 1. Общие принципы
- **PHP 8.4** - используйте типизированные свойства, параметры и возвращаемые типы в пределах ограничения Composer `~8.4.0`.
- **Строгий режим** - в каждом файле должна быть директива declare(strict_types=1);
- **Кодировка** - UTF-8 без BOM.
- **Обработка ошибок** - все исключения перехватываются глобальным обработчиком `JsonErrorHandler`, который всегда возвращает JSON-ответ в формате `ErrorDto`.
- **Логирование** - использовать стандартный компонент `log` Yii2, но исключать логирование 404-ошибок (настроено в `config/web.php`).

---

## 2. Соглашения по именованию

| Элемент                             | 	Соглашение                                           | 	Пример                                 |
|-------------------------------------|-------------------------------------------------------|-----------------------------------------|
| Классы                              | 	PascalCase                                           | 	UserController, IdRange                |
| Интерфейсы                          | 	PascalCase с префиксом `I`                           | 	IUserRepository                        |
| Трейты                              | 	PascalCase с суффиксом `Trait`                       | 	LoggableTrait                          |
| Абстрактные классы                  | 	PascalCase с префиксом `Abstract`                    | 	AbstractRepository                     |
| Исключения                          | 	PascalCase с суффиксом `Exception`                   | 	UserNotFoundException                  |
| DTO-классы                          | 	PascalCase с суффиксом `Dto`                         | 	UserDto, CollectionDto                 |
| Репозитории (интерфейсы/реализации) | 	PascalCase с суффиксом `Repository`                  | 	CommandRepository, IUserRepository     |
| Репозитории (реализации бд)         | 	PascalCase с суффиксом `Repository` и префиксом `Db` | 	DbCommandRepository, DbUserRepository  |
| Persistance (файлы записи бд)       | 	PascalCase с суффиксом `AR`                          | 	DbCommandRepository, DbUserRepository  |
| Value Object'ы                      | 	PascalCase, описывают сущность                       | 	IdRange, Email                         |
| Контроллеры                         | 	PascalCase с суффиксом `Controller`                  | 	UserController, DocsController         |
| Свойства / переменные               | 	camelCase                                            | 	$userName, $createdAt                  |
| Константы                           | 	UPPER_SNAKE_CASE                                     | 	STATUS_ACTIVE                          |
| Методы / функции                    | 	camelCase                                            | 	getUserById(), handleRequest()         |
| Приватные методы                    | 	camelCase с префиксом `_` (опционально)              | 	_formatArgs() (в шаблоне используется) |
| Файлы классов                       | 	соответствуют классу	                                | UserController.php                      |
| Конфигурационные файлы              | 	snake_case                                           | 	db.php, modules.php                    |
| Интегрирующие конфиг-файлы          | 	snake_case с префиксом `__`                          | 	__include.php                          |

---

## 3. DTO (Data Transfer Objects)
Все ответы контроллеров должны быть обёрнуты в один из стандартных DTO:
- `SuccessDto` - для успешных операций (возвращает `message` и/или `data`).
- `ItemDto` - для возврата одного объекта (свойство `item`).
- `CollectionDto` - для возврата коллекции с пагинацией (`items`, `_meta` с `total`, `page`, `limit`, `pages`).
- `ErrorDto` - для ошибок (свойства `code`, `message`, `details`).

Все DTO реализуют интерфейс `JsonSerializable` и сериализуются в JSON в соответствии с ожидаемой структурой.

- В контроллерах использовать вспомогательные методы `$this->success()`, `$this->item()`, `$this->collection()`, `$this->error()` (унаследованы от `BaseController`).
- Не возвращать произвольные массивы или объекты напрямую - только через DTO.
- Для ошибок всегда возвращать `ErrorDto` с соответствующим HTTP-кодом (устанавливается в `$response->statusCode`).

## 4. Контроллеры
- Все контроллеры должны наследоваться от `core\presentation\controller\BaseController` (или от `yii\rest\Controller` для модулей).
- **BaseController** предоставляет:
  - Методы `item()`, `collection()`, `success()`, `error()` для формирования ответов.
  - Методы для работы с пагинацией: `getPage()`, `getLimit()` (берут из GET-параметров page и `limit`).
  - Методы получения текущего пользователя: `getUserId()`, `getUserIdentity()` (используют `Yii::$app->user`).
  - Метод `parseIdRangeFromPath()` для разбора строк с диапазонами ID (например, `?ids=1,3:20`).
- Контроллеры должны быть **тонкими** - вся бизнес-логика выносится в сервисы или use-кейсы в `application` слое.
- **Именование действий:** `action<CamelCase>` (например, `actionIndex`, `actionView`, `actionCreate`).
- **Маршрутизация** - настраивается в `config/web.php` в секции `urlManager->rules`. Для REST-подобных эндпоинтов использовать правила с `'<controller:\w+>/<id:\d+>'` и т.п. Для модулей подключать внешний конфиг с путями модуля.

---

## 5. Обработка ошибок
- Глобальный обработчик `core\infrastructure\handler\JsonErrorHandler` переопределяет стандартный ErrorHandler Yii2.
- Все исключения преобразуются в JSON с использованием `ErrorDto`.
- Если включён режим отладки (`YII_DEBUG` и `DEBUG_LVL`):
  - `DEBUG_LVL=1` - добавляется `trace` в детали.
  - `DEBUG_LVL=2` - добавляется информация о запросе (метод, URL, заголовки, тело).
  - `DEBUG_LVL=3` - дополнительно выводятся аргументы в трейсе (ограничены по длине).
- В продакшене (`APP_ENV=prod`) детали ошибок не выводятся, только сообщение и код.
- Все ошибки логируются в `@runtime/logs/app.log` (настройка `log` компонента).

## 6. Код-стайл (форматирование)
Придерживаемся `PSR-12` со следующими дополнениями:
- Отступы - табуляция (1 таб = 4 пробела).
- Отступы в массивах и при инициалзации объектов - придерживаются в основной одной линии

Пример:
```php
'mailer' => [
    'class'     => Mailer::class,
    'viewPath'  => '@app/mail',
    'useFileTransport' => true, //<- вот это выбивается, но не мешает чтению
],
или
$result['_meta'] = [
    'total' => $this->total,
    'page'  => $this->page,
    'limit' => $this->limit,
    'pages' => $this->limit ? ceil($this->total / $this->limit) : null
];
```

- Описание методов/функций с 4мя и более входными параметрами разбиваются по строкам

Пример:
```php
public function __construct(
    public array $items,
    public ?int $total  = null,
    public ?int $page   = null,
    public ?int $limit  = null,
) {}
или
public function __construct(
    string $type,
    string $value,
    bool $confirmed,
    public ?int $limit = null,
) {
    $this->type     = $type;
    $this->value    = $value;
    $this->confirmed = $confirmed;
    $this->limit    = $limit;
}
```

- Фигурные скобки для управляющих конструкций, объектов и т.д. идут сразу за круглой скобкой через пробел, в той же строке

Пример:
```php
if (ex) {...
for (...) {...
public function method() {...
```

- В многострочных массивах и входных параметрах - запятая после последнего элемента.
- Для методов, возвращающих `void`, указывать `: void`.
- Комментировать публичные методы с помощью `phpDoc` (описание, `@param`, `@return`, `@throws`).
- Работа с разметкой файла:
  - От начала файла (где тег `<?php`) мы отступаем 1 строку;
  - От `declare(strict_types=1);` отступаем 1 строку
  - От namespace отступаем 1 строку
  - От импортов отступаем 1 строку
  - В конце файла должна быть 1 пустая строка

---

И помним о том что наш код не должен причинять вреда глазам и мозгу других участников команды *по возможности конечно)*

<p align="center">
  <img src="https://thescript.agency/posters/can%20handle%20it%20poster.svg" alt="I am file" >
  <br>
</p>
