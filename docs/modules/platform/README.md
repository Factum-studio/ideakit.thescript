# Platform

`Platform` — технический модуль монолита для надёжной доставки фоновых команд и исходящих сообщений. Реализованы PostgreSQL-таблица `outbox_messages`, её namespaced migration и Application-контракт записи. Таблица хранит намерение доставки и техническое состояние; бизнес-состояние остаётся у модуля-владельца сообщения.

## Реализованная схема

Модуль владеет [миграцией `outbox_messages`](../../../modules/platform/infrastructure/migrations/m260926_090000_create_outbox_messages_table.php) в namespace `modules\platform\infrastructure\migrations`. Таблица содержит 29 колонок и два допускающих `NULL` внешних ключа на `user.id` и `telegram_identity_profiles.id` с `RESTRICT`. Это связь общей PostgreSQL-схемы MVP, а не право Platform читать внутренние репозитории Core или Users. Ссылки на будущие публикации и версии идей пока остаются логическими.

PostgreSQL проверяет допустимые назначения и состояния, уникальность ключа идемпотентности, согласованность lease и времени повтора, структуру технической истории попыток, ограничения payload и срока его очистки. Частичный уникальный индекс допускает не более одной активной `IDEA_CARD` на получателя. Историю переходов и факт ручного разрешения ошибки таблица сама не устанавливает.

## Подготовленный контракт записи

[`IOutboxWriter`](../../../modules/platform/application/port/IOutboxWriter.php) принимает неизменяемый `OutboxWriteIntent` и возвращает `OutboxWriteReceipt` с исходом `CREATED` или `ALREADY_EXISTS`. Сейчас разрешён только маршрут `Telegram` / `telegram.update.received` / `1.0` / `TELEGRAM_UPDATE` → `RABBITMQ` / `critical`; payload содержит только внутренний UUID записи update. Неверный вход и неизвестный маршрут отклоняются безопасными кодами ошибок.

PostgreSQL-реализация writer и её подключение к приложению ещё не выполнены: пользоваться этим интерфейсом для записи пока нельзя. RabbitMQ topology, relay, worker, восстановление lease, очистка payload и Telegram webhook также не реализованы. Миграция не отправляет сообщения и не обращается к внешним системам.

## Проверка

Структура, ограничения и индексы проверяются [schema-тестом](../../../tests/integration/modules/platform/infrastructure/OutboxMessageSchemaTest.php). [Lifecycle-тест](../../../tests/integration/modules/platform/infrastructure/OutboxMigrationLifecycleTest.php) вызывает откат и повторное применение внутри откатываемой PostgreSQL-транзакции и сравнивает схемы родительских таблиц и Yii migration history.

В запущенном локальном Compose-окружении с подготовленной тестовой БД проверки выполняются так:

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure --no-colors
```

Проверка запускается только на отдельной тестовой PostgreSQL. Применение и откат миграции не выполняются автоматически при запуске приложения.
