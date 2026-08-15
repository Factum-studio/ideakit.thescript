# Модуль Users

## Назначение и текущий статус

Модуль `Users` владеет внутренними пользователями и связями их учётных записей с внешними провайдерами идентичности. Сейчас реализован только чистый Domain-срез для `UserIdentity` и `TelegramIdentityProfile`.

- `User` — внутренняя учётная запись и владелец общего статуса жизненного цикла. Сама сущность `User` в текущий срез не входит.
- `UserIdentity` — минимальная связь пользователя с провайдером `TELEGRAM`, `INTERNAL` или `PASSPORT`.
- `TelegramIdentityProfile` — Telegram-специфичный snapshot профиля и состояние доступности бота.

`UserIdentity` не имеет собственного статуса. При обезличивании у неё очищается только provider subject (`providerClientId`), а технические идентификаторы и время создания сохраняются.

## Реализованный Domain API

| Тип | Реализованное поведение |
|---|---|
| `UuidV7`, `UserId`, `UserIdentityId`, `TelegramIdentityProfileId` | Проверка и типобезопасное сравнение UUIDv7 |
| `ProviderClientId` | Непустой UTF-8 provider subject длиной до 255 символов без управляющих символов |
| `TelegramUserId` | Каноническая положительная десятичная строка в диапазоне PostgreSQL `bigint` и преобразование в `ProviderClientId` |
| `UserIdentity` | Создание, восстановление, проверка владельца и провайдера, идемпотентное обезличивание |
| `TelegramProfileSnapshot` | Nullable username, first name, last name и language code с проверкой UTF-8, управляющих символов и ограничений длины |
| `TelegramIdentityProfile` | Создание, восстановление, входящее взаимодействие, фиксация блокировки бота, обезличивание и проверка инициативной доставки |

`TelegramIdentityProfile` использует только состояния `ACTIVE`, `BOT_BLOCKED` и `ANONYMIZED`:

- новый профиль создаётся в `ACTIVE`;
- подтверждённая постоянная ошибка Telegram переводит `ACTIVE` в `BOT_BLOCKED` и сохраняет первоначальное время блокировки;
- допустимое входящее взаимодействие обновляет snapshot и возвращает заблокированный профиль в `ACTIVE`;
- `anonymize()` очищает snapshot и переводит профиль в необратимое состояние `ANONYMIZED`;

## Инварианты и ошибки

- Время передаётся вызывающим кодом и должно быть в UTC; Domain не читает системное время.
- `lastSeenAt` не может быть раньше `firstSeenAt` и не уменьшается при взаимодействиях.
- `blockedAt` присутствует только у `BOT_BLOCKED` и не может быть раньше `lastSeenAt`.
- Повторные `markBotBlocked()` и `anonymize()` идемпотентны.
- Входящее взаимодействие не восстанавливает обезличенный профиль.
- Domain не хранит raw Telegram updates и не зависит от Yii2, ActiveRecord, HTTP, Telegram SDK, PostgreSQL, Redis или RabbitMQ.
- Модель идентичности не создаёт административные сессии и не предоставляет административный доступ.

Ошибочные значения и переходы отклоняются узкими исключениями `InvalidProviderClientId`, `InvalidTelegramUserId`, `InvalidTelegramProfileSnapshot` и `TelegramProfileStateViolation`. Сообщения исключений содержат только безопасные коды причин без Telegram ID и данных профиля.

## Владение и взаимодействие модулей

Сущности и данные идентичности принадлежат модулю `Users`. Другие модули не должны обращаться к его будущим таблицам, repositories, mappers или внутренним Domain-классам напрямую.

Межмодульное взаимодействие должно появиться через отдельные типизированные Application-контракты. Telegram delivery отвечает за транспорт, updates и bot sessions, но не изменяет состояние `Users` в обход таких контрактов.

## Данные, зависимости и процессы

- Persistence, ActiveRecord, repositories, mappers и миграции для этого среза ещё не реализованы.
- Application commands, queries, handlers и публичные межмодульные контракты ещё не реализованы.
- Telegram webhook и API client, Redis, RabbitMQ, outbox и workers в этот срез не входят.
- У Domain-среза нет переменных окружения, runtime-конфигурации или внешних вызовов.
- Модуль работает внутри PHP/Yii2-монолита; отдельный сервис и transport-контракты для извлечения не определены.

Перед persistence-реализацией потребуется отдельное согласованное изменение существующей PostgreSQL-схемы для сохранения обезличенной identity. Оно не должно создавать дополнительную прикладную таблицу сверх утверждённой схемы.

## Тесты и проверка

Production-код находится в [`modules/users/domain`](../../../modules/users/domain), unit-тесты — в [`tests/unit/modules/users/domain`](../../../tests/unit/modules/users/domain).

Для текущего Domain-среза существуют шесть тестовых классов:

- `UuidV7Test`;
- `ProviderClientIdTest`;
- `TelegramUserIdTest`;
- `TelegramProfileSnapshotTest`;
- `UserIdentityTest`;
- `TelegramIdentityProfileTest`.

Следующие команды были фактически проверены в контейнерном runtime:

```bash
docker compose build php-fpm
docker compose run --rm --no-deps php-fpm vendor/bin/codecept run unit tests/unit/modules/users/domain
docker compose run --rm --no-deps php-fpm composer lint
docker compose run --rm --no-deps php-fpm vendor/bin/phpstan analyse modules/users --level=6 --no-progress
docker compose run --rm --no-deps php-fpm composer unclestan:6
```

Полный `composer test:unit` пока не проходит из-за существующих шаблонных тестов, которые ссылаются на отсутствующие классы `app\models\ContactForm`, `app\models\LoginForm`, `app\models\User` и `app\widgets\Alert`. Targeted-тесты модуля `Users` от этих классов не зависят.
