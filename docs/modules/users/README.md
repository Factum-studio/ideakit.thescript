# Модуль Users

## Назначение и текущий статус

Модуль `Users` владеет Telegram-специфичным профилем поверх существующего [User Core](../../core/user-circut/README.md). Сейчас в `Users` реализованы чистый Domain-срез `TelegramIdentityProfile` и его PostgreSQL persistence.

- `core` владеет `User`, единственной `UserIdentity`, общими идентификаторами и статусом пользователя `active/inactive`.
- Контракты Core сохраняются: provider и provider client ID остаются строками без `null`, код Telegram-провайдера — `telegram`.
- `TelegramIdentityProfile` — Telegram-специфичный snapshot профиля и состояние доступности бота.
- Application предоставляет внутренний repository port с типизированным результатом, содержащим профиль и версию хранения.
- Infrastructure содержит миграцию, ActiveRecord, mapper и PostgreSQL repository.

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

`seenAt` означает время получения доверенного входящего события приложением, а `blockedAt` — время наблюдения подтверждённой блокировки. При повторной обработке вызывающий слой должен сохранять исходное время события, а не подставлять текущее; Telegram `message.date` не служит универсальной меткой времени. Конкурентное сохранение защищено optimistic locking, а решение о повторной обработке и транзакционной границе остаётся ответственностью будущего Application-сценария.

## Владение и взаимодействие модулей

`core` владеет `User`, `UserIdentity`, таблицами `user` и `user_identity`; `Users` владеет профилем и таблицей `telegram_identity_profiles`. Единственная production-зависимость Domain профиля от Core — `UserIdentityId`. Профиль не импортирует Core entities, repositories, ActiveRecord или JWT; обратной зависимости `core → Users` нет. Persistence Core не изменялся.

Проверка существования identity, провайдера и общего доступа должна выполняться вне Domain профиля. Внутренний persistence port не является публичным межмодульным API; Application commands, queries и handlers ещё не реализованы. Другие модули не должны обращаться к таблице, repository, mapper или внутренним Domain-классам напрямую. Telegram delivery отвечает за транспорт, updates и bot sessions, но не изменяет состояние `Users` в обход будущих публичных Application-контрактов.

## Persistence

- Таблица `telegram_identity_profiles` связана с `user_identity` один к одному; внешний ключ использует `ON DELETE RESTRICT` и `ON UPDATE RESTRICT`.
- PostgreSQL constraints защищают допустимые статусы, временные инварианты, состояние обезличенного профиля и неотрицательные catalog checkpoints.
- `ITelegramIdentityProfileRepository` поддерживает поиск по ID профиля и `UserIdentityId`, добавление и сохранение с ожидаемой версией.
- `VersionedTelegramIdentityProfile` отделяет технический `lock_version` от Domain-сущности. Устаревшая версия возвращается как `TelegramIdentityProfileConcurrencyException`.
- Mapper нормализует время в UTC. Обычное сохранение не меняет identity, время первого взаимодействия и зарезервированные `catalog_*` поля.
- Repository не открывает скрытую транзакцию. Проверка `provider = telegram` и атомарное создание Core user, identity и Telegram-профиля относятся к следующему Application-срезу.

## Данные, зависимости и процессы

- Persistence реализован на Yii ActiveRecord и PostgreSQL только внутри Infrastructure; существующая реализация Core не заменяется.
- DI binding не добавлен, потому что Application use case — потребитель repository port — ещё не реализован.
- Application commands, queries, handlers и публичные межмодульные контракты ещё не реализованы.
- Telegram webhook и API client, Redis, RabbitMQ, outbox и workers в этот срез не входят.
- У Domain-среза нет переменных окружения, runtime-конфигурации или внешних вызовов.
- Модуль работает внутри PHP/Yii2-монолита; отдельный сервис и transport-контракты для извлечения не определены.

Обезличивание всего аккаунта требует отдельно согласованных Core/schema-контрактов. Изменения схемы Core и общей модели идентичности в этот срез не входят.

## Тесты и проверка

Production-код находится в [`modules/users`](../../../modules/users), unit-тесты — в [`tests/unit/modules/users`](../../../tests/unit/modules/users), PostgreSQL integration-тесты — в [`tests/integration`](../../../tests/integration).

Domain-поведение проверяют пять тестовых классов:

- `TelegramIdentityProfileIdTest`;
- `TelegramUserIdTest`;
- `TelegramProfileSnapshotTest`;
- `TelegramIdentityCompatibilityTest`;
- `TelegramIdentityProfileTest`.

Application-тест проверяет versioned persistence result. Integration-набор проверяет безопасное подключение к `ideakit_test`, фактическую схему PostgreSQL, ограничения, round-trip mapper/repository, уникальность, `RESTRICT`, optimistic locking и сохранение `catalog_*` полей. Применение, откат и повторное применение миграции отдельно проверены на `ideakit_test`. Все данные тестов синтетические.

Следующие команды были фактически проверены в контейнерном runtime:

```bash
docker compose build php-fpm
docker compose up -d --wait postgres php-fpm
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/users
docker compose exec -T php-fpm vendor/bin/codecept run integration
docker compose exec -T php-fpm composer lint
docker compose exec -T php-fpm vendor/bin/phpstan analyse modules/users --level=6 --no-progress
docker compose exec -T php-fpm composer unclestan:6
docker compose exec -T php-fpm composer test
```

Тест совместимости создаёт настоящую Core identity с UUIDv4 и проверяет, что блокировка и обезличивание профиля сохраняют её provider и provider client ID. Регрессионный тест проверяет, что запоздавшее входящее событие не меняет snapshot, статус и время профиля, а событие с временем блокировки допускает восстановление. Общий `composer test` включает существующие тесты Core и проходит вместе с тестами Users и PostgreSQL integration-набором.
