# Модуль Users

## Назначение и текущий статус

Модуль `Users` владеет Telegram-специфичным профилем поверх существующего [User Core](../../core/user-circut/README.md). Сейчас в `Users` реализован только чистый Domain-срез `TelegramIdentityProfile` и его Value Objects.

- `core` владеет `User`, единственной `UserIdentity`, общими идентификаторами и статусом пользователя `active/inactive`.
- Контракты Core сохраняются: provider и provider client ID остаются строками без `null`, код Telegram-провайдера — `telegram`.
- `TelegramIdentityProfile` — Telegram-специфичный snapshot профиля и состояние доступности бота.

Профиль ссылается на существующий `core\domain\valueObject\UserIdentityId`. Собственных `UserIdentity`, `UserId`, `UserIdentityId`, provider enum и общего provider client ID в `Users` нет.

## Реализованный Domain API

| Тип | Реализованное поведение |
|---|---|
| `TelegramIdentityProfileId` | Конструктор принимает UUIDv7, нормализует регистр; `value()` и `equals()` возвращают и сравнивают значение |
| `TelegramUserId` | `fromString()` принимает каноническую положительную десятичную строку в диапазоне PostgreSQL `bigint`; `value()` возвращает строку без преобразования в число, `equals()` сравнивает значения |
| `TelegramProfileSnapshot` | Nullable username, first name, last name и language code с проверкой UTF-8, управляющих символов и ограничений длины |
| `TelegramIdentityProfile` | `create()`, `restore()`, `recordIncomingInteraction()`, `markBotBlocked()`, `anonymize()` и локальная проверка `canReceiveInitiatedMessages()` |

Поля профиля доступны через `getId()`, `getUserIdentityId()`, `getProfileSnapshot()`, `getBotStatus()`, `getFirstSeenAt()`, `getLastSeenAt()` и `getBlockedAt()`. Проверка UUIDv7 относится только к ID самого профиля и не сужает существующий UUID-контракт Core identity.

`TelegramIdentityProfile` использует только состояния `ACTIVE`, `BOT_BLOCKED` и `ANONYMIZED`:

- новый профиль создаётся в `ACTIVE`;
- `markBotBlocked()` переводит `ACTIVE` в `BOT_BLOCKED` и сохраняет первоначальное время блокировки; классификация ответа Telegram находится вне Domain;
- допустимое входящее взаимодействие обновляет snapshot и возвращает заблокированный профиль в `ACTIVE`;
- `anonymize()` очищает snapshot и `blockedAt`, сохраняет технические идентификаторы, время первого и последнего взаимодействий и переводит профиль в необратимое состояние `ANONYMIZED`.

Обезличивание профиля не меняет Core identity или её provider client ID, не отзывает credentials и не означает обезличивания аккаунта. `canReceiveInitiatedMessages()` проверяет только локальный статус профиля, а не общий доступ пользователя, роли или административные права.

## Инварианты и ошибки

- Время передаётся вызывающим кодом и должно быть в UTC; Domain не читает системное время.
- `lastSeenAt` не может быть раньше `firstSeenAt` и не уменьшается при взаимодействиях.
- `blockedAt` присутствует только у `BOT_BLOCKED` и не может быть раньше `lastSeenAt`.
- Входящее взаимодействие с `seenAt < blockedAt` отклоняется без изменения состояния; равное время допускает восстановление `ACTIVE`.
- Повторные `markBotBlocked()` и `anonymize()` идемпотентны.
- Входящее взаимодействие не восстанавливает обезличенный профиль.
- `TelegramUserId` отклоняет пробелы, переводы строк, ведущие нули, знаки и значения вне диапазона; входная строка не обрезается и не преобразуется в `int` или `float`.
- Domain не хранит raw Telegram updates и не зависит от Yii2, ActiveRecord, HTTP, Telegram SDK, PostgreSQL, Redis или RabbitMQ.
- Профиль не создаёт административные сессии и не предоставляет административный доступ.

Ошибочные значения и переходы отклоняются узкими исключениями `InvalidTelegramUserIdException`, `InvalidTelegramProfileSnapshotException` и `TelegramProfileStateViolationException`. Некорректный ID профиля отклоняется через `InvalidArgumentException`. Сообщения исключений содержат только безопасные коды причин без Telegram ID и данных профиля.

`seenAt` означает время получения доверенного входящего события приложением, а `blockedAt` — время наблюдения подтверждённой блокировки. При повторной обработке вызывающий слой должен сохранять исходное время события, а не подставлять текущее; Telegram `message.date` не служит универсальной меткой времени. Порядок операций с одинаковым временем и конкурентный доступ остаются ответственностью будущего Application/persistence-сценария.

## Владение и взаимодействие модулей

`core` владеет `User`, `UserIdentity`, таблицами `user` и `user_identity`; `Users` владеет профилем и его будущим persistence в `telegram_identity_profiles`. Единственная production-зависимость Domain профиля от Core — `UserIdentityId`. Профиль не импортирует Core entities, repositories, ActiveRecord или JWT; обратной зависимости `core → Users` нет.

Проверка существования identity, провайдера и общего доступа должна выполняться вне Domain профиля. Публичные типизированные Application-контракты для работы с профилем ещё не реализованы. Другие модули не должны обращаться к его будущим таблицам, repositories, mappers или внутренним Domain-классам напрямую. Telegram delivery отвечает за транспорт, updates и bot sessions, но не изменяет состояние `Users` в обход Application-контрактов.

## Данные, зависимости и процессы

- Persistence, ActiveRecord, repositories, mappers и миграции Telegram-профиля ещё не реализованы; существующая реализация Core не заменяется.
- Application commands, queries, handlers и публичные межмодульные контракты ещё не реализованы.
- Telegram webhook и API client, Redis, RabbitMQ, outbox и workers в этот срез не входят.
- У Domain-среза нет переменных окружения, runtime-конфигурации или внешних вызовов.
- Модуль работает внутри PHP/Yii2-монолита; отдельный сервис и transport-контракты для извлечения не определены.

Обезличивание всего аккаунта требует отдельно согласованных Core/schema-контрактов. Изменения схемы и общей модели идентичности в этот Domain-срез не входят.

## Тесты и проверка

Production-код находится в [`modules/users/domain`](../../../modules/users/domain), unit-тесты — в [`tests/unit/modules/users/domain`](../../../tests/unit/modules/users/domain).

Для текущего Domain-среза существуют пять тестовых классов:

- `TelegramIdentityProfileIdTest`;
- `TelegramUserIdTest`;
- `TelegramProfileSnapshotTest`;
- `TelegramIdentityCompatibilityTest`;
- `TelegramIdentityProfileTest`.

Следующие команды были фактически проверены в контейнерном runtime:

```bash
docker compose build php-fpm
docker compose run --rm --no-deps php-fpm vendor/bin/codecept run unit tests/unit/modules/users/domain
docker compose run --rm --no-deps php-fpm vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no --path-mode=override modules/users/domain tests/unit/modules/users/domain
docker compose run --rm --no-deps php-fpm vendor/bin/phpstan analyse modules/users --level=6 --no-progress
docker compose run --rm --no-deps php-fpm composer test:unit
docker compose run --rm --no-deps php-fpm composer lint
docker compose run --rm --no-deps php-fpm composer unclestan:6
```

Тест совместимости создаёт настоящую Core identity с UUIDv4 и проверяет, что блокировка и обезличивание профиля сохраняют её provider и provider client ID. Регрессионный тест проверяет, что запоздавшее входящее событие не меняет snapshot, статус и время профиля, а событие с временем блокировки допускает восстановление. Общий `composer test:unit` включает существующие тесты Core и проходит вместе с тестами Users.
