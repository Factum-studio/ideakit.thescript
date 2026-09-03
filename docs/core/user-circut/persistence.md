# Persistence и ERD

## Таблица `user`

Создаётся миграцией `m260813_110316_create_user_table`.

| Поле | Тип/ограничение | Назначение |
|---|---|---|
| `id` | UUID, PK | идентификатор пользователя |
| `surname` | varchar(100), NOT NULL | фамилия |
| `name` | varchar(100), NOT NULL | имя |
| `patronymic` | varchar(100), NULL | отчество |
| `email` | varchar(255), UNIQUE, NULL | email |
| `phone` | varchar(20), UNIQUE, NULL | телефон |
| `role` | varchar(50), NOT NULL, default `user` | роль |
| `post` | varchar(100), NULL | должность |
| `status` | smallint, NOT NULL, default `1` | активность |
| `auth_key` | varchar(32), NOT NULL | auth key |
| `created_at` | timestamp, NOT NULL | создание |
| `updated_at` | timestamp, NOT NULL | изменение |
| `last_login_at` | timestamp, NULL | последний вход |

Индексы: `email`, `phone`, `status`, `auth_key`. Email и phone также уникальны.

## Таблица `user_identity`

Создаётся миграцией `m260813_112057_create_user_identity_table`.

| Поле | Тип/ограничение | Назначение |
|---|---|---|
| `id` | UUID, PK | идентификатор identity |
| `user_id` | UUID, FK | пользователь |
| `provider` | varchar(50), NOT NULL | провайдер |
| `provider_client_id` | varchar(255), NOT NULL | id пользователя у провайдера |
| `created_at` | timestamp, NOT NULL | создание связи |

Есть уникальный составной индекс `(provider, provider_client_id)` и индекс `user_id`.

Foreign key `fk-user_identity-user_id` использует `CASCADE` для delete/update.

## PostgreSQL UUID

Миграции первоначально создают идентификаторы как строки длиной 36, а для PostgreSQL меняют тип колонок `id`/`user_id` на native `uuid` через `ALTER TABLE ... USING ...::uuid`.

Domain IDs генерируются через `Ramsey\Uuid\Uuid::uuid7()`.

## Persistence mapping

`DbUserRepository` преобразует `User` ↔ `UserAR`.

`DbUserIdentityRepository` преобразует `UserIdentity` ↔ `UserIdentityAR`.

Таким образом ActiveRecord не выходит за Infrastructure boundary.

## Transaction boundary

В проекте присутствуют `ITransactionManager` и `DbTransactionManager`, но текущий `config/container.php` не регистрирует `ITransactionManager`, а `AuthenticateUseCase` не оборачивает создание пользователя + identity + last login в транзакцию. Поэтому текущая реализация не должна документироваться как атомарная transaction-based authentication flow.
