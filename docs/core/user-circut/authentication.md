# Аутентификация и JWT

## Вход по provider identity

`AuthenticateUseCase::execute(AuthRequestDto)` принимает:

- `provider` — идентификатор провайдера;
- `providerClientId` — идентификатор пользователя внутри провайдера;
- `userData` — профильные данные провайдера.

Алгоритм:

1. Ищется `UserIdentity` по `(provider, providerClientId)`.
2. Если identity существует, из неё получается `user_id` и загружается `User`.
3. Если identity не существует, пользователь создаётся из `userData`, после чего создаётся identity.
4. Если identity отсутствует для существующего пользователя, она добавляется.
5. Выполняется `User::updateLastLogin()` и пользователь сохраняется.
6. `JwtManager::generate()` создаёт JWT.
7. `UserDto` помещается в `AuthResponseDto`.

## Создание пользователя из provider data

При автоматическом создании отсутствующие значения имеют fallback:

- surname → `Unknown`;
- name → `User`;
- role → `user`;
- status → `1`.

`auth_key` генерируется через `ISecurityService` длиной 32.

## JWT

`JwtManager` использует секрет из `JWT_SECRET` и TTL из `JWT_TTL`, по умолчанию `3600` секунд.

Алгоритм подписи: `HS256`.

Payload:

| Claim | Источник |
|---|---|
| `sub` | `User.id` |
| `role` | `User.role` |
| `iat` | текущее Unix-время |
| `exp` | `iat + TTL` |
| `auth_key` | `User.authKey` |

`JwtManager::validate()` возвращает `JwtPayloadDto` либо `null`. При ошибке decode/signature/expiry или отсутствии обязательных полей возвращается `null`.

## Входящий запрос

Токен ищется в следующем порядке:

1. `Authorization: Bearer <token>`;
2. cookie `access_token`.

Если токена нет — `401 Token not found.`.

Если JWT невалиден или истёк — `401 Invalid or expired token.`.

После успешной проверки JWT middleware загружает пользователя по `payload.userId` и требует активный статус. Если пользователь отсутствует или неактивен — `401 Account is inactive or not found.`.

После этого создаётся `YiiIdentity` и передаётся в `Yii::$app->user`.

## Ротация auth key

`RegenerateAuthKeyHandler` генерирует новый ключ и сохраняет пользователя. Это предназначено для смены ключа, который также находится внутри новых JWT.

Важно: текущий `JwtMiddleware` не сравнивает `payload.authKey` с актуальным `User.authKey`; такое сравнение реализовано в `YiiIdentity::findIdentityByAccessToken()`.

## Security notes

- Секрет JWT не хранится в коде: `config/container.php` читает `JWT_SECRET` из environment.
- Cookie и Bearer token поддерживаются одновременно.
- Session/autologin Yii отключены.
- Статус пользователя участвует в авторизации запроса.
