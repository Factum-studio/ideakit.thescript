# Модуль Telegram

## Реализованная часть

Подготовлена PostgreSQL-таблица `telegram_bot_sessions` для хранения состояния диалога.
Обработка Telegram updates, управление диалогом и отправка сообщений не реализованы этой миграцией.

## Владение данными

Telegram владеет состоянием диалога. Профиль принадлежит Users, общая учётная запись и идентичности — Core.
Сессия связана с `telegram_identity_profiles.id` внешним ключом с `RESTRICT`.
Внешний ключ не разрешает прикладному коду Telegram обращаться к таблицам или репозиториям Users.
Административная аутентификация не связана с таблицей сессий бота.

## Хранение и ограничения

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

## Миграции

[Миграция таблицы](../../../modules/telegram/infrastructure/migrations/m260913_090000_create_telegram_bot_sessions_table.php)
зарегистрирована в [общем списке namespace](../../../config/migration_namespaces.php).
Она применяется после миграции Telegram-профиля в общей истории Yii migrations.

В тестовом окружении явно переопределите `DB_DSN`: entrypoint `tests/bin/yii` использует console-конфигурацию.
Одного `TEST_DB_DSN` для команды миграций недостаточно.

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm php tests/bin/yii migrate/up --interactive=0
```

Пример предназначен для существующей локальной тестовой БД; DSN передаётся одним аргументом.
Перед применением проверьте фактическое имя БД и список новых миграций.
Откат удаляет таблицу сессий и её данные. Проверять rollback разрешается только на отдельной временной БД;
не используйте `fresh` или общий откат для рабочей базы.

## Проверки

[Schema integration-тест](../../../tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php)
проверяет колонки, defaults, FK, уникальность, CHECK и индексы на PostgreSQL.

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php
docker compose exec -T php-fpm composer lint
docker compose exec -T php-fpm composer unclestan:6
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm composer test
```

Эти тесты используют настроенную `ideakit_test`; её схема должна быть подготовлена.
Тест форматирования самого тестового файла запускается отдельно, поскольку общий formatter исключает `tests`:

```bash
docker compose exec -T php-fpm vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=override tests/integration/modules/telegram/infrastructure/TelegramBotSessionSchemaTest.php
```

## Граница выделения модуля

Сейчас целостность с профилем обеспечивается FK общей PostgreSQL-базы.
При выделении Telegram в отдельный сервис этот FK потребует замены проверяемым публичным контрактом.
Текущая миграция не создаёт межсервисную инфраструктуру.
