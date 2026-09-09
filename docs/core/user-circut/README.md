# User Core

## Назначение

`core` содержит текущую реализацию пользовательского контура: доменную модель пользователя и внешних identity, прикладные handlers/use case, JWT-аутентификацию, Yii identity, persistence через ActiveRecord и миграции PostgreSQL.

Документ описывает фактическую реализацию из текущего состояния кода.

## Слои

```text
Presentation
    ↓
Application
    ↓
Domain
    ↑
Infrastructure
```

### Presentation

- `core/presentation/controller/BaseController.php` — базовые REST-ответы, pagination и доступ к текущему Yii identity.
- `core/presentation/controller/DocsController.php` — выдача Markdown-документации через ScriptDoc.
- `core/presentation/controller/SwaggerController.php` — генерация OpenAPI из `core` и `modules`.

### Application

Содержит команды, запросы, DTO, handlers и `AuthenticateUseCase`.

Основные точки входа:

- `AuthenticateUseCase` — аутентификация по внешней identity, создание пользователя, выдача JWT.
- `CreateUserHandler` — создание пользователя.
- `UpdateUserHandler` — изменение профиля/роли/статуса.
- `DeleteUserHandler` — удаление пользователя вместе с identity.
- `AddUserIdentityHandler` — привязка identity.
- `GetUserHandler` — получение пользователя с опциональным `identities`.
- `FindUserHandler` — фильтрация и пагинация.
- `GetUserByIdentityHandler` — поиск пользователя по provider + provider client id.
- `ResolveUserIdentityHandler` — нейтральное разрешение существующей identity или атомарное создание обычного пользователя и identity.
- `RegenerateAuthKeyHandler` — ротация `auth_key`.

`ResolveUserIdentityHandler` принимает строковые `provider` и `providerClientId`, а также время в UTC. Для новой identity он создаёт только обычного активного пользователя с ролью `user`; `name` и `surname` такого пользователя могут быть `null`. Результат содержит идентификаторы пользователя и identity, общий статус пользователя и признак создания.

Core не знает о Telegram-профиле и его статусах. Разрешение внешней identity не создаёт административную сессию и не предоставляет административный доступ.

### Domain

Основные сущности:

- `User`
- `UserIdentity`

`User` допускает отсутствие `name` и `surname`, когда общий аккаунт создаётся через канал, который хранит профильные данные в своём owning-модуле.

Value Objects:

- `UserId`
- `UserIdentityId`
- `Email`
- `Phone`
- `Role`
- `UserStatus`
- `IdRange`

Доменные исключения реализуют `IHttpException` через базовый `DomainException`.

### Infrastructure

- PostgreSQL persistence: `UserAR`, `UserIdentityAR`, `DbUserRepository`, `DbUserIdentityRepository`, `DbTransactionManager`.
- JWT: `JwtManager`, `JwtValidator`.
- Security: `YiiSecurityService`.
- Yii error handling: `JsonErrorHandler`.
- Миграции: `core/infrastructure/migrations`.

### Security bridge

`core/security/YiiIdentity` адаптирует доменный `User` под `yii\web\IdentityInterface`.

`core/security/JwtMiddleware` выполняет обязательную проверку JWT перед обычными web-запросами, кроме маршрутов, перечисленных в `config/ignore_routes.php`.

## Dependency Injection

`config/container.php` регистрирует:

- `IUserRepository → DbUserRepository`;
- `IUserIdentityRepository → DbUserIdentityRepository`;
- singleton `ITransactionManager → DbTransactionManager`;
- `ISecurityService → YiiSecurityService`;
- `JwtManager` и `JwtValidator`;
- handlers, включая `ResolveUserIdentityHandler`;
- `AuthenticateUseCase`;
- `JwtMiddleware`.

## Документация контура

- [Архитектура](./architecture.md)
- [Аутентификация и JWT](./authentication.md)
- [Управление пользователем](./user-management.md)
- [Persistence и БД](./persistence.md)
- [Middleware](./middleware.md)
- [API DTO](./dto.md)

Диаграммы находятся отдельно в `/docs/.diargams/`.
