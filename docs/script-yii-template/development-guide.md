# Руководство по разработке на шаблоне
В этом разделе описаны практические шаги для создания новых модулей, контроллеров, команд и использования ключевых компонентов.

## Создание нового API-контроллера
1. Создайте класс контроллера в core/presentation/controller/ или в модуле. 
2. Наследуйте его от BaseController. 
3. Определите действия (actionIndex, actionView, actionCreate, etc.). 
4. Используйте готовые методы для формирования ответов:
   - `$this->collection(...)` для списков.
   - `$this->item(...)` для одного объекта.
   - `$this->success(...)` для успешных операций.
   - `$this->error(...)` для ошибок.
5. Настройте маршруты в `config/web.php` или через правила модуля.

Пример контроллера пользователей:
```php
namespace core\presentation\controller;

class UserController extends BaseController
{
    public function actionIndex()
    {
        // Получить пользователей из репозитория
        $users = UserRepository::find()->all();
        $total = UserRepository::count();
        return $this->collection($users, $total, $this->getPage(), $this->getLimit());
    }

    public function actionView($id)
    {
        $user = UserRepository::findOne($id);
        if (!$user) {
            return $this->error('User not found', 404);
        }
        return $this->item($user);
    }
}
```

## Использование Value Object IdRange
`IdRange` полезен для обработки параметров запроса вида `ids=1,3:20,27`. Он парсит строку и возвращает массив целых чисел. Пример использования:
```php
public function actionBatch(IdRange $ids)
{
    // автоматически через параметр маршрута, если тип подсказан
    $users = UserRepository::findAll($ids->toArray());
    return $this->collection($users);
}
```

Либо через ручной парсинг:
```php
public function actionIndex()
{
    $idsParam = Yii::$app->request->get('ids');
    $idRange = $this->parseIdRangeFromPath($idsParam);
    if ($idRange && !$idRange->isEmpty()) {
        $users = UserRepository::findAll($idRange->toArray());
    } else {
        $users = UserRepository::find()->all();
    }
    return $this->collection($users);
}
```

## Работа с CQRS (команды и запросы)
Шаблон содержит заготовки для CQRS в `core/application/command/`, `core/application/query/` и `core/application/handler/`. Рекомендуется следующий подход:
1. Определите команду или запрос как DTO (например, `CreateUserCommand`).
2. Создайте соответствующий хендлер в `core/application/handler/`.
3. В контроллере вызовите хендлер через шину команд (можно реализовать через внедрение зависимости или фасад).
   
Пример:
```php
// core/application/command/CreateUserCommand.php
class CreateUserCommand
{
    public string $name;
    public string $email;
    public string $password;
}

// core/application/handler/CreateUserHandler.php
class CreateUserHandler
{
    public function handle(CreateUserCommand $command): User
    {
        // логика создания пользователя
    }
}

// в контроллере
public function actionCreate()
{
    $command = new CreateUserCommand();
    $command->name = Yii::$app->request->post('name');
    $command->email = Yii::$app->request->post('email');
    $command->password = Yii::$app->request->post('password');
    $user = (new CreateUserHandler())->handle($command);
    return $this->item($user);
}
```
Для более сложных сценариев рекомендуется внедрять шину команд (например, через `yii\di\Container` или отдельный сервис).

## Создание модуля
Модули предназначены для группировки функциональности (например, `telegram-bot`, `admin-portal`). Они располагаются в папке `modules/`.
1. Создайте папку `modules/my-module/`.
2. Создайте класс Module (наследник `yii\base\Module`).
3. Зарегистрируйте модуль в `config/modules.php`:
```php
return [
    'my-module' => [
        'class' => 'modules\my-module\Module',
        'controllerNamespace' => 'modules\my-module\presentation\controller',
    ],
];
```
4. Внутри модуля организуйте ту же структуру (presentation, application, domain, infrastructure), или используйте упрощённую структуру.

## Миграции и работа с БД
Миграции располагаются в `core/infrastructure/migrations/` или в отдельных модулях. Для применения миграций используйте консольную команду:
```
php yii migrate
```
Пространства имён миграций настраиваются в `config/migration_namespaces.php`. Пример:
```php
return [
    'core\\infrastructure\\migrations',
    'modules\\my-module\\infrastructure\\migrations',
];
```

## Тестирование
Для тестирования используется Codeception. Конфигурация в `codeception.yml`. Тесты пишутся в `tests/`.
`Юнит-тесты` - для отдельных классов.
`Функциональные тесты` - для контроллеров и API.
`Приёмные тесты` - для UI (если используется).

Запуск тестов:
```
./vendor/bin/codecept run
```

## Работа с окружением
Устаревшие web- и Docker Compose-конфигурации шаблона удалены, поскольку не соответствуют утверждённой матрице runtime. Воспроизводимый запуск через PHP-FPM, Nginx, PostgreSQL, Redis и RabbitMQ будет добавлен отдельным подпунктом; до этого локальный запуск не считается доступной процедурой. Актуальные версии перечислены в [корневом README](../../README.md#утверждённая-матрица-runtime).

## Статический анализ и проверка качества
Шаблон включает игнорирование для `scancore` (ручной статический анализатор) через `.scancoreignore`. При необходимости добавьте свой инструмент (`PHPStan`, `Psalm`) и настройте его.
