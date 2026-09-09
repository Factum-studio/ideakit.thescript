# Модуль Users

## Назначение и текущий статус

Модуль `Users` владеет Telegram-специфичным профилем поверх существующего [User Core](../../core/user-circut/README.md). Сейчас в `Users` реализованы Domain-модель `TelegramIdentityProfile`, PostgreSQL persistence и публичные Application-команды разрешения Telegram identity и фиксации подтверждённой блокировки бота.

- `core` владеет `User`, единственной `UserIdentity`, общими идентификаторами и статусом пользователя `active/inactive`.
- Контракты Core сохраняются: provider и provider client ID остаются строками без `null`, код Telegram-провайдера — `telegram`.
- `TelegramIdentityProfile` — Telegram-специфичный snapshot профиля и состояние доступности бота.
- Application предоставляет публичные команды и handlers, а также внутренние порты identity resolver, repository, генератора ID и транзакции.
- Infrastructure содержит миграцию, ActiveRecord, mapper, PostgreSQL repository, адаптер к публичному Core resolver и общую транзакционную границу.

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

`seenAt` означает время получения доверенного входящего события приложением, а `blockedAt` — время наблюдения подтверждённой блокировки. При повторной обработке вызывающий слой должен сохранять исходное время события, а не подставлять текущее; Telegram `message.date` не служит универсальной меткой времени. Application handlers повторно читают состояние при разрешённых конкурентных конфликтах, а сохранение защищено optimistic locking.

## Владение и взаимодействие модулей

`core` владеет `User`, `UserIdentity`, таблицами `user` и `user_identity`; `Users` владеет профилем и таблицей `telegram_identity_profiles`. Единственная production-зависимость Domain профиля от Core — `UserIdentityId`. Профиль не импортирует Core entities, repositories, ActiveRecord или JWT; обратной зависимости `core → Users` нет. Persistence Core не изменялся.

Проверка существования identity и общего статуса пользователя выполняется через consumer-owned порт `IUserIdentityResolver`. Его Infrastructure-адаптер `CoreUserIdentityResolver` вызывает публичный нейтральный `ResolveUserIdentityHandler` Core с provider `telegram`; Users не обращается к Core repositories, ActiveRecord или таблицам напрямую. Внутренний persistence port не является публичным межмодульным API. Telegram delivery должен вызывать публичные Application commands Users и не изменять профиль напрямую.

## Публичные Application-команды

| Команда и handler | Вход | Типизированный результат |
|---|---|---|
| `ResolveTelegramIdentityCommand` → `ResolveTelegramIdentityHandler` | Telegram user ID, nullable `username` / `firstName` / `lastName` / `languageCode`, доверенное `observedAt` в UTC, correlation ID | `ResolvedTelegramIdentity` с user/identity/profile IDs, статусами, correlation ID и outcome `CREATED`, `PROFILE_CREATED`, `UPDATED`, `UNCHANGED`, `USER_INACTIVE`, `PROFILE_ANONYMIZED` или `STALE_IGNORED` |
| `MarkTelegramProfileBlockedCommand` → `MarkTelegramProfileBlockedHandler` | ID профиля, причина `BOT_BLOCKED_BY_USER`, доверенное `blockedAt` в UTC, correlation ID | `TelegramProfileBlockResult` со статусом, временем блокировки, correlation ID и outcome `BLOCKED`, `ALREADY_BLOCKED`, `PROFILE_ANONYMIZED` или `STALE_IGNORED` |

Команда разрешения создаёт Core user/identity и профиль либо обновляет существующий профиль в одной PostgreSQL-транзакции. Неактивный Core user возвращает `USER_INACTIVE` без создания или изменения профиля. Обезличенный профиль не восстанавливается. Команда блокировки принимает только уже подтверждённую транспортным слоем причину и не классифицирует ответы Telegram API самостоятельно.

`ResolveTelegramIdentityHandler` выполняет максимум три попытки только для `UserIdentityResolutionConcurrencyException`, конфликта уникальной связи профиля `TelegramIdentityProfileAlreadyExistsException` и optimistic-lock конфликта `TelegramIdentityProfileConcurrencyException`. После третьего конфликта возвращается `TelegramIdentityResolutionConcurrencyException`. `MarkTelegramProfileBlockedHandler` повторяет только optimistic-lock конфликт и после третьей попытки возвращает `TelegramProfileBlockConcurrencyException`. Ошибки целостности, обычные persistence-ошибки и некорректные команды не повторяются.

## Persistence

- Таблица `telegram_identity_profiles` связана с `user_identity` один к одному; внешний ключ использует `ON DELETE RESTRICT` и `ON UPDATE RESTRICT`.
- PostgreSQL constraints защищают допустимые статусы, временные инварианты, состояние обезличенного профиля и неотрицательные catalog checkpoints.
- `ITelegramIdentityProfileRepository` поддерживает поиск по ID профиля и `UserIdentityId`, добавление и сохранение с ожидаемой версией.
- `VersionedTelegramIdentityProfile` отделяет технический `lock_version` от Domain-сущности. Устаревшая версия возвращается как `TelegramIdentityProfileConcurrencyException`.
- Mapper нормализует время в UTC. Обычное сохранение не меняет identity, время первого взаимодействия и зарезервированные `catalog_*` поля.
- Repository не открывает скрытую транзакцию. `DbTransactionRunner` использует тот же singleton `ITransactionManager`, что и Core resolver, поэтому Core user, identity и Telegram-профиль фиксируются или откатываются вместе.

## Данные, зависимости и процессы

- Persistence реализован на Yii ActiveRecord и PostgreSQL только внутри Infrastructure; существующая реализация Core не заменяется.
- Production DI связывает публичные handlers с Core resolver adapter, PostgreSQL repository, UUIDv7 generator и общей транзакцией; web, console и tests используют одну конфигурацию контейнера.
- Telegram webhook, Telegram API client, delivery orchestration, Redis, RabbitMQ, outbox и workers в этот срез не входят.
- У Domain-среза нет переменных окружения, runtime-конфигурации или внешних вызовов.
- Модуль работает внутри PHP/Yii2-монолита. При будущем выделении в сервис `IUserIdentityResolver` заменяется API adapter, а публичные Application commands и typed results сохраняются как граница поведения.

Обезличивание всего аккаунта требует отдельно согласованных Core/schema-контрактов. Изменения схемы Core и общей модели идентичности в этот срез не входят.

## Тесты и проверка

Production-код находится в [`modules/users`](../../../modules/users), unit-тесты — в [`tests/unit/modules/users`](../../../tests/unit/modules/users), PostgreSQL integration-тесты — в [`tests/integration`](../../../tests/integration).

Domain-поведение проверяют пять тестовых классов:

- `TelegramIdentityProfileIdTest`;
- `TelegramUserIdTest`;
- `TelegramProfileSnapshotTest`;
- `TelegramIdentityCompatibilityTest`;
- `TelegramIdentityProfileTest`.

Unit-набор проверяет публичные команды, handlers, retry-классификацию и адаптер Core resolver. Integration-набор проверяет безопасное подключение к `ideakit_test`, фактическую схему PostgreSQL, ограничения, round-trip mapper/repository, уникальность, `RESTRICT`, optimistic locking, сохранение `catalog_*` полей, production DI и общую транзакцию Core/Users. Два отдельно запущенных PHP-процесса подтверждают, что конкурентное первое разрешение оставляет один согласованный набор user/identity/profile. Все данные тестов синтетические.

Следующие команды были фактически проверены в контейнерном runtime:

```bash
docker compose build php-fpm
docker compose up -d --wait postgres php-fpm
docker compose exec -T php-fpm php tests/bin/yii migrate/up --interactive=0
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/application
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/domain
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/users
docker compose exec -T php-fpm vendor/bin/codecept run integration
docker compose exec -T php-fpm php yii help
docker compose exec -T php-fpm composer lint
docker compose exec -T php-fpm vendor/bin/phpstan analyse core modules/users --level=6 --no-progress
docker compose exec -T php-fpm composer unclestan:6
docker compose exec -T php-fpm composer test
```

Тест совместимости создаёт настоящую Core identity с UUIDv4 и проверяет, что блокировка и обезличивание профиля сохраняют её provider и provider client ID. Регрессионный тест проверяет, что запоздавшее входящее событие не меняет snapshot, статус и время профиля, а событие с временем блокировки допускает восстановление. Дополнительно проверяются границы зависимостей Core/Users и полный PostgreSQL-переход `ACTIVE → BOT_BLOCKED → ACTIVE`. Свежий общий `composer test` прошёл: 433 теста и 1956 проверок; PostgreSQL integration-набор — 41 тест и 235 проверок.
