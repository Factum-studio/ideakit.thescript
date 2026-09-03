# Управление пользователем

## CreateUserHandler

`CreateUserCommand` содержит фамилию, имя, отчество, email, phone, role, post и status.

Перед созданием выполняется проверка уникальности email и phone через `IUserRepository`. Затем создаётся UUID v7, генерируется auth key и создаётся `User`.

## UpdateUserHandler

`UpdateUserCommand` является частично изменяемым объектом. Поля, переданные как `null`, сохраняют текущие значения.

Изменяются:

- профиль;
- role;
- status.

Каждое изменение обновляет `User.updatedAt`.

## DeleteUserHandler

Перед удалением проверяется наличие пользователя. Затем сначала удаляются все `user_identity`, после чего удаляется `user`.

На уровне БД также существует foreign key `user_identity.user_id → user.id` с `CASCADE` для delete/update.

## AddUserIdentityHandler

Проверяет существование пользователя и уникальность `(provider, providerClientId)`. После этого создаёт `UserIdentity` с UUID v7 и сохраняет её.

## GetUserHandler

Возвращает `UserDto`. По умолчанию identities не загружаются.

При `GetUserQuery::expand`, содержащем `identities`, вызывается `IUserIdentityRepository::findByUserId()` и identities добавляются в DTO.

## GetUserByIdentityHandler

Ищет identity по `(provider, providerClientId)`, затем загружает пользователя по `user_id` и возвращает `UserDto`.

## FindUserHandler

Поддерживаются фильтры:

- email;
- phone;
- role;
- post;
- status;
- createdFrom / createdTo;
- updatedFrom / updatedTo;
- lastLoginFrom / lastLoginTo;
- limit / offset;
- orderBy.

`FindUserQuery` по умолчанию использует `limit=20`, `page=1`. Handler рассчитывает `offset = (page - 1) * limit`.

Ответ — `CollectionDto` с `items` и `_meta` (`total`, `page`, `limit`, `pages`).

## RegenerateAuthKeyHandler

Находит пользователя, генерирует новый 32-символьный random string через `ISecurityService`, меняет auth key и сохраняет пользователя.

## Роли

Текущий Value Object разрешает только:

- `user`;
- `admin`;
- `manager`.

`Role::isAdmin()` проверяет только `admin`.

## Статусы

Текущий Value Object разрешает:

- `1` — active;
- `0` — inactive.

`UserStatus::isActive()` возвращает `true` только для `1`.
