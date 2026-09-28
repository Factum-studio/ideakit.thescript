# Platform

`Platform` — технический модуль монолита для надёжной доставки фоновых команд и исходящих сообщений. Реализованы PostgreSQL-таблица `outbox_messages`, её namespaced migration и типизированная команда записи. Таблица хранит намерение доставки и техническое состояние; бизнес-состояние остаётся у модуля-владельца сообщения.

## Реализованная схема

Модуль владеет [миграцией `outbox_messages`](../../../modules/platform/infrastructure/migrations/m260926_090000_create_outbox_messages_table.php) в namespace `modules\platform\infrastructure\migrations`. Таблица содержит 29 колонок и два допускающих `NULL` внешних ключа на `user.id` и `telegram_identity_profiles.id` с `RESTRICT`. Это связь общей PostgreSQL-схемы MVP, а не право Platform читать внутренние репозитории Core или Users. Ссылки на будущие публикации и версии идей пока остаются логическими.

PostgreSQL проверяет допустимые назначения и состояния, уникальность ключа идемпотентности, согласованность lease и времени повтора, структуру технической истории попыток, ограничения payload и срока его очистки. Частичный уникальный индекс допускает не более одной активной `IDEA_CARD` на получателя. Историю переходов и факт ручного разрешения ошибки таблица сама не устанавливает.

## Запись в PostgreSQL

[`IOutboxWriter`](../../../modules/platform/application/port/IOutboxWriter.php) принимает неизменяемый `OutboxWriteIntent` и возвращает `OutboxWriteReceipt` с ID сообщения и исходом `CREATED` или `ALREADY_EXISTS`. Сейчас разрешён только маршрут `Telegram` / `telegram.update.received` / `1.0` / `TELEGRAM_UPDATE` → `RABBITMQ` / `critical`; payload содержит только внутренний UUID записи update, совпадающий с ID агрегата, и ограничен 1 КиБ. Произвольный JSON и raw Telegram update не принимаются.

[`DbOutboxWriter`](../../../modules/platform/infrastructure/db/DbOutboxWriter.php) подключён к интерфейсу в общей [DI-конфигурации](../../../config/container.php) web- и console-приложений. Он использует существующий компонент `db` и записывает сообщение только внутри уже открытой транзакции вызывающего сценария; сам не открывает и не завершает транзакцию. Новый UUIDv7, начальный `PENDING`, техническую историю попыток и SHA-256 детерминированного JSON устанавливает writer.

Совпадающий повтор возвращает прежний ID без изменения строки, включая состояние доставки, correlation ID и время. Повтор распознаётся и после очистки payload по сохранённому hash и неизменяемым метаданным. Другой эффект с тем же ключом отклоняется. `OutboxWriteException` различает `invalid_intent`, `unsupported_route`, `transaction_required`, `idempotency_conflict` и `persistence_failure`; публичный текст не содержит SQL или входных данных. Ошибку БД writer не повторяет: rollback и повтор всего сценария принадлежат вызывающему коду.

RabbitMQ topology, relay, worker, восстановление lease, очистка payload и Telegram webhook ещё не реализованы. Запись не отправляет сообщения и не обращается к внешним системам.

## Проверка

Структура, ограничения и индексы проверяются [schema-тестом](../../../tests/integration/modules/platform/infrastructure/OutboxMessageSchemaTest.php). [Lifecycle-тест](../../../tests/integration/modules/platform/infrastructure/OutboxMigrationLifecycleTest.php) вызывает откат и повторное применение внутри откатываемой PostgreSQL-транзакции и сравнивает схемы родительских таблиц и Yii migration history. [Тест writer](../../../tests/integration/modules/platform/infrastructure/DbOutboxWriterTest.php) проверяет запись, общий commit/rollback, повторы и конкурентный конфликт в тестовой PostgreSQL.

[DI-тест](../../../tests/integration/config/PlatformOutboxContainerBindingsTest.php) подтверждает общее соединение и rollback для web- и console-конфигураций. [Архитектурный тест](../../../tests/unit/modules/platform/PlatformArchitectureTest.php) защищает публичный Application-контракт от framework/Infrastructure-зависимостей и Platform Infrastructure от импорта Telegram internals.

В запущенном локальном Compose-окружении с подготовленной тестовой БД проверки выполняются так:

```bash
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/platform --no-colors
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure --no-colors
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/config/PlatformOutboxContainerBindingsTest.php --no-colors
```

Проверка запускается только на отдельной тестовой PostgreSQL. Применение и откат миграции не выполняются автоматически при запуске приложения.
