# Архитектура User Core

## Контекст

Текущий `core` реализован как часть Yii2-приложения с разделением на Presentation, Application, Domain и Infrastructure. Пользовательский контур отделён от Yii ActiveRecord: прикладной и доменный код работают через repository ports, а PostgreSQL детали находятся в Infrastructure.

## Основной dependency flow

```text
HTTP
 ↓
Yii Application / beforeRequest
 ↓
JwtMiddleware
 ↓
YiiIdentity
 ↓
Presentation Controller
 ↓
Application Handler / UseCase
 ↓
Application Port
 ↓
Infrastructure Repository
 ↓
UserAR / UserIdentityAR
 ↓
PostgreSQL
```

Для authentication flow вместо controller используется `AuthenticateUseCase`, который получает `AuthRequestDto`, работает с двумя repository ports, при необходимости создаёт пользователя и identity, обновляет `last_login_at` и создаёт JWT.

## CQRS-like структура

Записывающие операции оформлены как Commands + Handlers:

- `CreateUserCommand` → `CreateUserHandler`;
- `UpdateUserCommand` → `UpdateUserHandler`;
- `DeleteUserCommand` → `DeleteUserHandler`;
- `AddUserIdentityCommand` → `AddUserIdentityHandler`;
- `RegenerateAuthKeyCommand` → `RegenerateAuthKeyHandler`.

Читающие операции оформлены как Queries + Handlers:

- `GetUserQuery` → `GetUserHandler`;
- `FindUserQuery` → `FindUserHandler`;
- `GetUserByIdentityQuery` → `GetUserByIdentityHandler`.

Это CQRS-подобное разделение на уровне application API; отдельные read/write базы или event bus в текущем user core не реализованы.

## Domain model

`User` содержит профиль, роль, статус, auth key и временные поля. `UserIdentity` связывает пользователя с внешним провайдером.

`User` поддерживает изменение профиля, роли, статуса, auth key и `last_login_at`. Identity может быть добавлена в коллекцию доменной сущности, но фактическое хранение выполняется отдельным repository.

## Persistence boundary

Application знает только интерфейсы:

```text
IUserRepository
IUserIdentityRepository
ISecurityService
ITransactionManager
```

Concrete implementations находятся в Infrastructure. `ITransactionManager` и `DbTransactionManager` присутствуют в коде, однако в `config/container.php` текущего дампа отдельная регистрация `ITransactionManager` отсутствует и authentication use case его не использует.

## Важные фактические ограничения

1. `JwtMiddleware` вызывается глобально из `config/web.php` в `beforeRequest`, а не подключается как обычный controller action filter.
2. `config/ignore_routes.php` сейчас исключает маршруты с префиксом `docs`.
3. JWT использует HS256.
4. JWT содержит `sub`, `role`, `iat`, `exp`, `auth_key`.
5. Middleware после проверки JWT дополнительно читает пользователя из repository и проверяет активность.
6. `YiiIdentity::findIdentityByAccessToken()` дополнительно сверяет `auth_key`, поэтому ротация auth key инвалидирует ранее выпущенные токены при использовании этого Yii API.
7. В `JwtMiddleware` непосредственно показанная реализация проверяет подпись/срок JWT и активность пользователя, но отдельного сравнения `payload.authKey` с текущим `User.authKey` в этом middleware нет.
