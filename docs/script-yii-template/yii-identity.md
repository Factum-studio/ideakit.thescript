# YiiIdentity - кастомная реализация IdentityInterface

Класс `core\security\YiiIdentity` реализует интерфейс `yii\web\IdentityInterface`. В текущей версии шаблона он представляет собой заготовку, которую необходимо дополнить под конкретную модель пользователя.

## Методы интерфейса
- `getId()` - должен возвращать уникальный идентификатор пользователя (например, `$this->user->id`). В шаблоне возвращает `void` (пропущен `return`), что требует реализации.
- `getAuthKey()` - возвращает ключ аутентификации (для "запомнить меня"), по умолчанию `null`.
- `validateAuthKey($authKey)` - проверяет ключ, всегда `false`.
- `findIdentity($id)` - статический метод поиска пользователя по ID, возвращает `null`.
- `findIdentityByAccessToken($token, $type = null)` - статический метод поиска по токену доступа, возвращает `null`.

## Рекомендации по доработке
Обычно этот класс используется в связке с JWT-аутентификацией. В шаблоне есть заготовленные комментарии:
```php
/**
 * TODO: impl getUser
 * public function getUser(): User { return $this->user; }
 *
 * TODO: impl getJwtToken
 * public function getJwtToken(): ?JwtToken { return $this->jwtToken; }
 */
```
Таким образом, разработчик должен:
- Добавить свойства (например, `private User $user`, `private ?JwtToken $jwtToken`).
- Реализовать методы `findIdentity` и `findIdentityByAccessToken` для загрузки пользователя из БД.
- Реализовать `getId()`.
- При необходимости реализовать методы для JWT.
- При необходимости реализовать методы-геттеры.

## Настройка в конфигурации
В `config/web.php` компонент `user` настроен:
```php
'user' => [
    'identityClass'     => YiiIdentity::class,
    'enableAutoLogin'   => false,
    'enableSession'     => false,
],
```
Сессии отключены, что подразумевает использование токенов (например, JWT) для аутентификации.
