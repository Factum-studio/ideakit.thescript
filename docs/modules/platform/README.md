# Platform

`Platform` — технический модуль монолита для надёжной доставки фоновых команд и исходящих сообщений. Реализованы PostgreSQL-таблица `outbox_messages`, её namespaced migration и типизированная команда записи. Таблица хранит намерение доставки и техническое состояние; бизнес-состояние остаётся у модуля-владельца сообщения.

## Реализованная схема

Модуль владеет [миграцией `outbox_messages`](../../../modules/platform/infrastructure/migrations/m260926_090000_create_outbox_messages_table.php) в namespace `modules\platform\infrastructure\migrations`. Таблица содержит 29 колонок и два допускающих `NULL` внешних ключа на `user.id` и `telegram_identity_profiles.id` с `RESTRICT`. Это связь общей PostgreSQL-схемы MVP, а не право Platform читать внутренние репозитории Core или Users. Ссылки на будущие публикации и версии идей пока остаются логическими.

PostgreSQL проверяет допустимые назначения и состояния, уникальность ключа идемпотентности, согласованность lease и времени повтора, структуру технической истории попыток, ограничения payload и срока его очистки. Частичный уникальный индекс допускает не более одной активной `IDEA_CARD` на получателя. Историю переходов и факт ручного разрешения ошибки таблица сама не устанавливает.

## Запись в PostgreSQL

[`IOutboxWriter`](../../../modules/platform/application/port/IOutboxWriter.php) принимает неизменяемый `OutboxWriteIntent` и возвращает `OutboxWriteReceipt` с ID сообщения и исходом `CREATED` или `ALREADY_EXISTS`. Сейчас разрешён только маршрут `Telegram` / `telegram.update.received` / `1.0` / `TELEGRAM_UPDATE` → `RABBITMQ` / `critical`; payload содержит только внутренний UUID записи update, совпадающий с ID агрегата, и ограничен 1 КиБ. Произвольный JSON и raw Telegram update не принимаются.

[`DbOutboxWriter`](../../../modules/platform/infrastructure/db/DbOutboxWriter.php) подключён к интерфейсу в общей [DI-конфигурации](../../../config/container.php) web- и console-приложений. Он использует существующий компонент `db` и записывает сообщение только внутри уже открытой транзакции вызывающего сценария; сам не открывает и не завершает транзакцию. Новый UUIDv7, начальный `PENDING`, техническую историю попыток и SHA-256 детерминированного JSON устанавливает writer.

Совпадающий повтор возвращает прежний ID без изменения строки, включая состояние доставки, correlation ID и время. Повтор распознаётся и после очистки payload по сохранённому hash и неизменяемым метаданным. Другой эффект с тем же ключом отклоняется. `OutboxWriteException` различает `invalid_intent`, `unsupported_route`, `transaction_required`, `idempotency_conflict` и `persistence_failure`; публичный текст не содержит SQL или входных данных. Ошибку БД writer не повторяет: rollback и повтор всего сценария принадлежат вызывающему коду.

Запись не отправляет сообщения и не обращается к внешним системам. Relay, worker, восстановление lease, очистка payload и Telegram webhook ещё не реализованы.

## RabbitMQ: конфигурация и топология

[`IBrokerTopology`](../../../modules/platform/application/port/IBrokerTopology.php) предоставляет явную команду `declare()`. Infrastructure-адаптер [`RabbitMqTopology`](../../../modules/platform/infrastructure/rabbitmq/RabbitMqTopology.php) объявляет durable direct exchanges `ideakit.commands` и `ideakit.dead-letter`, durable quorum queues `critical` и `critical.failed` и bindings с одноимёнными routing keys. Повторная декларация сохраняет сообщения. Несовместимая декларация возвращает безопасный `topology_mismatch`, не удаляя существующие объекты; сетевой сбой — `connection_failure`. Соединение закрывается после операции. Один локальный узел RabbitMQ не обеспечивает HA.

DLX-настройки не входят в аргументы AMQP-декларации. [Tracked policy](../../../docker/rabbitmq/critical-policy.json) для `^critical$` отдельно задаёт `ideakit.dead-letter` / `critical.failed`, `dead-letter-strategy=at-least-once` и `overflow=reject-publish`. [Скрипт применения](../../../docker/rabbitmq/apply-policy.sh) использует локальный broker CLI, отклоняет несовместимую одноимённую policy, конкурирующую policy с равным/большим приоритетом и подходящую operator policy. Точное повторное применение безопасно; PHP-клиент не использует management API. Фактическая доставка через DLX будет проверена вместе с receiver.

[`config/rabbitmq.php`](../../../config/rabbitmq.php) создаёт неизменяемую Infrastructure-конфигурацию из окружения без открытия соединения. Host, user, password и vhost обязательны: непустые строки до 255 байт без управляющих символов. `RABBITMQ_PORT` — целое число 1–65535. Числовые строки проверяются до преобразования; ошибочные параметры дают `configuration_invalid` без входных значений. Фабрика использует закреплённую в Composer lock `php-amqplib` 3.7.4 и расширения `mbstring`/`sockets`; конструкторы не выполняют I/O, AMQP debug отключён.

| Переменная | Default | Граница |
|---|---:|---|
| `RABBITMQ_CONNECTION_TIMEOUT` | 3 s | > 0, ≤ 30 s |
| `RABBITMQ_CHANNEL_RPC_TIMEOUT` | 5 s | > 0, ≤ 30 s, не больше I/O timeout |
| `RABBITMQ_HEARTBEAT` | 10 s | integer 1–60 s |
| `RABBITMQ_READ_TIMEOUT`, `RABBITMQ_WRITE_TIMEOUT` | 25 s | > 2 × heartbeat, ≤ 120 s |
| `RABBITMQ_CONFIRM_TIMEOUT` | 5 s | > 0, ≤ 30 s |
| `RABBITMQ_CONSUMER_POLL_TIMEOUT` | 1 s | > 0, ≤ 30 s |

Compose передаёт PHP host `rabbitmq` и внутренний порт 5672. Для native-запуска [.env.example](../../../.env.example) использует `127.0.0.1` и опубликованный порт; реальный `.env` не требуется контейнерам. Топология и publisher пока не подключены к DI; console-команда и receiver ещё не реализованы. Обычный bootstrap не объявляет топологию и не публикует сообщения.

Policy применяется отдельно в запущенном локальном брокере:

```bash
docker compose exec -T rabbitmq su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh
docker compose exec -T rabbitmq su-exec rabbitmq rabbitmqctl -q list_policies -p ideakit
```

Изолированный брокер запускается только с profile `messaging-test`, использует vhost `ideakit_transport_test`, синтетические credentials и tmpfs вместо рабочего volume. Его host-порт публикуется на `127.0.0.1:${TEST_RABBITMQ_PORT:-5673}`; контейнерные тесты используют `rabbitmq-test:5672`. Test configuration требует `APP_ENV=test` и явные `TEST_RABBITMQ_HOST`, `PORT`, `USER`, `PASSWORD`, `VHOST`, не подставляя рабочие credentials. Fixtures работают только с выделенным vhost и своими ресурсами; рабочие очереди не очищаются.

Проверенные команды подготовки и запуска транспортных тестов:

```bash
docker compose --profile messaging-test up -d --wait rabbitmq-test postgres
docker compose exec -T rabbitmq-test su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/platform --no-colors
docker compose exec -T -e APP_ENV=test -e TEST_RABBITMQ_HOST=rabbitmq-test -e TEST_RABBITMQ_PORT=5672 -e TEST_RABBITMQ_USER=transport-test -e TEST_RABBITMQ_PASSWORD=local-transport-test-only -e TEST_RABBITMQ_VHOST=ideakit_transport_test php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure/rabbitmq --no-colors
```

Перед PHP-проверками образ должен быть пересобран через `docker compose build php-fpm`: исходники не монтируются с хоста. [Configuration unit-тест](../../../tests/unit/modules/platform/infrastructure/rabbitmq/RabbitMqConnectionConfigTest.php) проверяет параметры и безопасные ошибки. [Broker integration-тест](../../../tests/integration/modules/platform/infrastructure/rabbitmq/RabbitMqTransportTest.php) подтверждает повторную декларацию, сохранность сообщения, оба routing paths и отказ без удаления несовместимого exchange. Тесты выполняются последовательно; недоступный broker является ошибкой, а не skip.

## RabbitMQ: подтверждённая публикация

[`IBrokerPublisher`](../../../modules/platform/application/port/IBrokerPublisher.php) принимает неизменяемый `BrokerEnvelope` и возвращает `BrokerPublishReceipt` только с подтверждённым outbox UUID. Envelope содержит outbox UUID, тип и версию команды, correlation UUID и существующий `TelegramUpdateReceivedPayload` с внутренним UUID update. Writer и его PostgreSQL-транзакция не меняются; publisher не обращается к БД.

[`BrokerEnvelopeCodec`](../../../modules/platform/infrastructure/rabbitmq/BrokerEnvelopeCodec.php) сериализует ровно `outbox_id`, `message_type`, `schema_version`, `correlation_id`, `payload`; payload содержит только `update_id`. UUID канонические, типы проверяются без преобразования, неизвестные поля отклоняются. Ограничения: payload ≤ 1024 байт, envelope ≤ 4096 байт, JSON depth ≤ 16. Malformed JSON, усечённое тело и несовпадение AMQP properties дают `invalid_envelope`. AMQP `message_id` и `correlation_id` совпадают с envelope; `content_type=application/json`, `delivery_mode=2`. Codec проверяет техническую структуру, а не разрешение типа/версии для dispatch; прикладной registry ещё не реализован.

[`RabbitMqPublisher`](../../../modules/platform/infrastructure/rabbitmq/RabbitMqPublisher.php) открывает собственные connection/channel для одной публикации в `ideakit.commands` с ключом `critical`, включает confirms и отправляет persistent message с `mandatory=true`. Успех требует ack без `basic.return`. Return вместе с ack даёт `unroutable`; nack — `nacked`, истечение ограниченного ожидания — `confirm_timeout`, сетевой сбой — `connection_failure`. Публичные исключения не содержат библиотечных деталей или цепочки исходного исключения. Channel и connection закрываются в `finally`; ошибка закрытия не подменяет первоначальный отказ. Конструктор не выполняет I/O.

Автоматических повторов нет. Два явных вызова с тем же outbox UUID могут создать две доставки с одинаковым `message_id`: это at-least-once, не exactly-once. Confirm доказывает принятие брокером, но не выполнение команды. При потере подтверждения результат может быть неоднозначным; повтор и идемпотентная обработка принадлежат будущим relay/worker.

[Codec unit-тест](../../../tests/unit/modules/platform/infrastructure/rabbitmq/BrokerEnvelopeCodecTest.php) проверяет wire contract и границы. [Confirmation unit-тест](../../../tests/unit/modules/platform/infrastructure/rabbitmq/PublishConfirmationTest.php) проверяет ack, return+ack, nack, потерю соединения и конечный deadline без продления входящими событиями. Broker integration-тест дополнительно проверяет реальную публикацию и её properties, два явных вызова без скрытого повтора, unroutable return+ack и закрытый локальный порт. Получение с manual ack, DLX delivery, relay и постоянный worker пока не реализованы.

## Проверка

Структура, ограничения и индексы проверяются [schema-тестом](../../../tests/integration/modules/platform/infrastructure/OutboxMessageSchemaTest.php). [Lifecycle-тест](../../../tests/integration/modules/platform/infrastructure/OutboxMigrationLifecycleTest.php) вызывает откат и повторное применение внутри откатываемой PostgreSQL-транзакции и сравнивает схемы родительских таблиц и Yii migration history. [Тест writer](../../../tests/integration/modules/platform/infrastructure/DbOutboxWriterTest.php) проверяет запись, общий commit/rollback, повторы и конкурентный конфликт в тестовой PostgreSQL.

[DI-тест](../../../tests/integration/config/PlatformOutboxContainerBindingsTest.php) подтверждает общее соединение и rollback для web- и console-конфигураций. [Архитектурный тест](../../../tests/unit/modules/platform/PlatformArchitectureTest.php) защищает публичный Application-контракт от framework/Infrastructure-зависимостей и Platform Infrastructure от импорта Telegram internals.

В запущенном локальном Compose-окружении с подготовленными тестовой БД и изолированным брокером проверки выполняются так:

```bash
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/platform --no-colors
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e TEST_RABBITMQ_HOST=rabbitmq-test -e TEST_RABBITMQ_PORT=5672 -e TEST_RABBITMQ_USER=transport-test -e TEST_RABBITMQ_PASSWORD=local-transport-test-only -e TEST_RABBITMQ_VHOST=ideakit_transport_test php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure --no-colors
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/config/PlatformOutboxContainerBindingsTest.php --no-colors
```

Проверка запускается только на отдельной тестовой PostgreSQL. Применение и откат миграции не выполняются автоматически при запуске приложения.
