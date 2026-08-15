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
- `RegenerateAuthKeyHandler` — ротация `auth_key`.

### Domain

Основные сущности:

- `User`
- `UserIdentity`

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

- PostgreSQL persistence: `UserAR`, `UserIdentityAR`, `DbUserRepository`, `DbUserIdentityRepository`.
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
- `ISecurityService → YiiSecurityService`;
- `JwtManager` и `JwtValidator`;
- handlers;
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
