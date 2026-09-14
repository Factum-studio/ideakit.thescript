# Модуль Telegram

## Реализованная часть

Подготовлены PostgreSQL-таблицы `telegram_bot_sessions` для состояния диалога и `telegram_updates`
для надёжного приёма входящих обновлений. Webhook, разбор обновлений, управление диалогом и отправка
сообщений в этом срезе не реализованы.

## Владение данными

Telegram владеет состоянием диалога и inbox входящих обновлений. Профиль принадлежит Users,
общая учётная запись и идентичности — Core. Сессия и опционально входящее обновление связаны
с `telegram_identity_profiles.id` внешними ключами с `RESTRICT`.
Эти внешние ключи не разрешают прикладному коду Telegram обращаться к таблицам или репозиториям Users.
Административная аутентификация не связана с таблицами Telegram.

## Хранение и ограничения

### Сессии

- На сочетание `(bot_key, chat_id)` допускается одна сессия.
- Состояния: `BROWSING`, `AWAITING_LEAD_COMMENT`, `AWAITING_LEAD_CONFIRMATION`, `LEAD_REPLY`.
- Источник содержимого: `DEMO` или `CATALOG`.
- Комментарий и срок его действия заполняются вместе только в состоянии подтверждения.
- `interaction_revision` и `lock_version` неотрицательны, начинаются с нуля и имеют разные назначения.
- `content_subject_id` не является физическим FK или источником подтверждённого показа идеи.
- `agency_lead_id` — nullable UUID без FK в текущей реализации.
- Технические времена создания и изменения получают начальное значение от PostgreSQL.
  Последующие записи должны явно обновлять `updated_at`.

Миграция не реализует увеличение ревизий, optimistic locking, истечение срока черновика или проверку доступа к заявке.
Публичных Application-контрактов, HTTP endpoints, workers, Redis-ключей и внешних адаптеров в этом срезе нет.

### Входящие обновления

- Типы обновлений: `MESSAGE`, `CALLBACK_QUERY`, `MY_CHAT_MEMBER`, `UNSUPPORTED`.
- Состояния обработки: `RECEIVED`, `PROCESSING`, `RETRY_SCHEDULED`, `PROCESSED`, `FAILED`, `IGNORED`.
- Повторное обновление одного бота отсекается уникальной парой `(bot_key, update_id)`.
- Связь с Telegram-профилем nullable: обновление можно принять до разрешения identity.
- Lease-поля обязательны только для `PROCESSING`; время следующей попытки — только для `RETRY_SCHEDULED`.
- В терминальных состояниях обязательно `processed_at`, а исходный payload уже отсутствует.
- Исходный JSON хранится только для продолжимых состояний, не дольше 30 дней с момента приёма.
- Хеш payload хранится как lowercase SHA-256; число попыток не может быть отрицательным.

Схема не реализует захват lease, повторные попытки, очистку payload или переходы между состояниями.
Repository, Application-команд, webhook, workers, RabbitMQ и scheduler в этом срезе нет.

## Миграции

[Миграция таблицы сессий](../../../modules/telegram/infrastructure/migrations/m260913_090000_create_telegram_bot_sessions_table.php)
и [миграция inbox](../../../modules/telegram/infrastructure/migrations/m260913_172000_create_telegram_updates_table.php)
зарегистрированы в [общем списке namespace](../../../config/migration_namespaces.php).
Они применяются после миграции Telegram-профиля в общей истории Yii migrations.

В тестовом окружении явно переопределите `DB_DSN`: entrypoint `tests/bin/yii` использует console-конфигурацию.
Одного `TEST_DB_DSN` для команды миграций недостаточно.

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm php tests/bin/yii migrate/up --interactive=0
```

Пример предназначен для существующей локальной тестовой БД; DSN передаётся одним аргументом.
Перед применением проверьте фактическое имя БД и список новых миграций.
Откат удаляет таблицу соответствующего среза и её данные. Проверять rollback разрешается только на отдельной временной БД;
не используйте `fresh` или общий откат для рабочей базы.

## Проверки

[Schema integration-тест сессий](../../../tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php),
[schema integration-тест inbox](../../../tests/integration/modules/telegram/infrastructure/TelegramUpdateSchemaTest.php)
и [lifecycle-тест migrations](../../../tests/integration/modules/telegram/infrastructure/TelegramMigrationsLifecycleTest.php)
проверяют колонки, defaults, FK, уникальность, `CHECK`, индексы, регистрацию namespace и безопасный цикл
отката с повторным применением на PostgreSQL.

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramUpdateSchemaTest.php
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramMigrationsLifecycleTest.php
docker compose exec -T php-fpm composer lint
docker compose exec -T php-fpm composer unclestan:6
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm composer test
```

Эти тесты используют настроенную `ideakit_test`; её схема должна быть подготовлена.
Тест форматирования самого тестового файла запускается отдельно, поскольку общий formatter исключает `tests`:

```bash
docker compose exec -T php-fpm vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php
docker compose exec -T php-fpm vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override tests/integration/modules/telegram/infrastructure/TelegramUpdateSchemaTest.php
docker compose exec -T php-fpm vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override tests/integration/modules/telegram/infrastructure/TelegramMigrationsLifecycleTest.php
```

## Граница выделения модуля

Сейчас целостность с профилем обеспечивается FK общей PostgreSQL-базы.
При выделении Telegram в отдельный сервис этот FK потребует замены проверяемым публичным контрактом.
Текущая миграция не создаёт межсервисную инфраструктуру.
