# JWT Middleware

## Где подключается

Middleware запускается из `config/web.php` через событие `beforeRequest`.

Перед вызовом `JwtMiddleware::handle()` проверяется `config/ignore_routes.php`.

Сейчас исключены маршруты, начинающиеся с `docs`, поэтому документация и Swagger доступны без JWT.

## Алгоритм

```text
Request
  |
  v
beforeRequest
  |
  +-- route ignored? -- yes --> Controller
  |
  no
  v
JwtMiddleware::handle()
  |
  +-- Authorization Bearer?
  |       |
  |       no
  |       v
  |    access_token cookie?
  |
  +-- no token --> 401
  |
  v
JwtManager::validate()
  |
  +-- null --> 401
  |
  v
IUserRepository::findById(payload.userId)
  |
  +-- missing/inactive --> 401
  |
  v
new YiiIdentity(User)
  |
  v
Yii::$app->user->setIdentity()
  |
  v
Controller
```

## Источники токена

### Authorization header

Ожидается формат:

```text
Authorization: Bearer <JWT>
```

### Cookie

Используется cookie:

```text
access_token=<JWT>
```

Cookie проверяется только если Bearer token отсутствует.

## Что проверяется

`JwtManager::validate()` проверяет JWT через Firebase JWT с алгоритмом HS256, используя секрет из DI.

После этого middleware проверяет наличие пользователя и `UserStatus::isActive()`.

## Что происходит после успешной проверки

Создаётся `YiiIdentity`, содержащий доменный `User`, и устанавливается в `Yii::$app->user`.

Это позволяет controllers использовать `BaseController::getUserId()` и `BaseController::getUserIdentity()`.

## Ошибки

| Условие | Ответ |
|---|---|
| Нет Bearer/cookie | HTTP 401 `Token not found.` |
| JWT invalid/expired | HTTP 401 `Invalid or expired token.` |
| Пользователь отсутствует | HTTP 401 `Account is inactive or not found.` |
| Пользователь inactive | HTTP 401 `Account is inactive or not found.` |

## Важное отличие от YiiIdentity

`YiiIdentity::findIdentityByAccessToken()` имеет дополнительную проверку `payload.authKey === User.authKey`. `JwtMiddleware::handle()` такой проверки не выполняет. Эти два пути нельзя описывать как полностью идентичные.
