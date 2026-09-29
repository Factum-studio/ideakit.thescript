# Platform

`Platform` — технический модуль монолита для надёжной доставки фоновых команд и исходящих сообщений. Реализованы PostgreSQL-таблица `outbox_messages`, типизированная запись, RabbitMQ-транспорт и ограниченный outbox relay. Таблица хранит намерение доставки и техническое состояние; бизнес-состояние остаётся у модуля-владельца сообщения.

## Реализованная схема

Модуль владеет [миграцией `outbox_messages`](../../../modules/platform/infrastructure/migrations/m260926_090000_create_outbox_messages_table.php) в namespace `modules\platform\infrastructure\migrations`. Таблица содержит 29 колонок и два допускающих `NULL` внешних ключа на `user.id` и `telegram_identity_profiles.id` с `RESTRICT`. Это связь общей PostgreSQL-схемы MVP, а не право Platform читать внутренние репозитории Core или Users. Ссылки на будущие публикации и версии идей пока остаются логическими.

PostgreSQL проверяет допустимые назначения и состояния, уникальность ключа идемпотентности, согласованность lease и времени повтора, структуру технической истории попыток, ограничения payload и срока его очистки. Частичный уникальный индекс допускает не более одной активной `IDEA_CARD` на получателя. Историю переходов и факт ручного разрешения ошибки таблица сама не устанавливает.

## Запись в PostgreSQL

[`IOutboxWriter`](../../../modules/platform/application/port/IOutboxWriter.php) принимает неизменяемый `OutboxWriteIntent` и возвращает `OutboxWriteReceipt` с ID сообщения и исходом `CREATED` или `ALREADY_EXISTS`. Сейчас разрешён только маршрут `Telegram` / `telegram.update.received` / `1.0` / `TELEGRAM_UPDATE` → `RABBITMQ` / `critical`; payload содержит только внутренний UUID записи update, совпадающий с ID агрегата, и ограничен 1 КиБ. Произвольный JSON и raw Telegram update не принимаются.

[`DbOutboxWriter`](../../../modules/platform/infrastructure/db/DbOutboxWriter.php) подключён к интерфейсу в общей [DI-конфигурации](../../../config/container.php) web- и console-приложений. Он использует существующий компонент `db` и записывает сообщение только внутри уже открытой транзакции вызывающего сценария; сам не открывает и не завершает транзакцию. Новый UUIDv7, начальный `PENDING`, техническую историю попыток и SHA-256 детерминированного JSON устанавливает writer.

Совпадающий повтор возвращает прежний ID без изменения строки, включая состояние доставки, correlation ID и время. Повтор распознаётся и после очистки payload по сохранённому hash и неизменяемым метаданным. Другой эффект с тем же ключом отклоняется. `OutboxWriteException` различает `invalid_intent`, `unsupported_route`, `transaction_required`, `idempotency_conflict` и `persistence_failure`; публичный текст не содержит SQL или входных данных. Ошибку БД writer не повторяет: rollback и повтор всего сценария принадлежат вызывающему коду.

Запись не отправляет сообщения и не обращается к внешним системам. Публикация выполняется отдельным relay; worker, восстановление lease, очистка payload и Telegram webhook ещё не реализованы.

## RabbitMQ: конфигурация и топология

[`IBrokerTopology`](../../../modules/platform/application/port/IBrokerTopology.php) предоставляет явную команду `declare()`. Infrastructure-адаптер [`RabbitMqTopology`](../../../modules/platform/infrastructure/rabbitmq/RabbitMqTopology.php) объявляет durable direct exchanges `ideakit.commands` и `ideakit.dead-letter`, durable quorum queues `critical` и `critical.failed` и bindings с одноимёнными routing keys. Повторная декларация сохраняет сообщения. Несовместимая декларация возвращает безопасный `topology_mismatch`, не удаляя существующие объекты; сетевой сбой — `connection_failure`. Соединение закрывается после операции. Один локальный узел RabbitMQ не обеспечивает HA.

DLX-настройки не входят в аргументы AMQP-декларации. [Tracked policy](../../../docker/rabbitmq/critical-policy.json) для `^critical$` отдельно задаёт `ideakit.dead-letter` / `critical.failed`, `dead-letter-strategy=at-least-once` и `overflow=reject-publish`. [Скрипт применения](../../../docker/rabbitmq/apply-policy.sh) использует локальный broker CLI, отклоняет несовместимую одноимённую policy, конкурирующую policy с равным/большим приоритетом и подходящую operator policy. Точное повторное применение безопасно; PHP-клиент не использует management API. Integration-тест подтверждает доставку через DLX, в том числе после восстановления временно отсутствовавшего error binding.

[`config/rabbitmq.php`](../../../config/rabbitmq.php) создаёт неизменяемую Infrastructure-конфигурацию из окружения без открытия соединения. Host, user, password и vhost обязательны: непустые строки до 255 байт без управляющих символов. `RABBITMQ_PORT` — целое число 1–65535. Числовые строки проверяются до преобразования; ошибочные параметры дают `configuration_invalid` без входных значений. Фабрика использует закреплённую в Composer lock `php-amqplib` 3.7.4 и расширения `mbstring`/`sockets`; конструкторы не выполняют I/O, AMQP debug отключён.

| Переменная | Default | Граница |
|---|---:|---|
| `RABBITMQ_CONNECTION_TIMEOUT` | 3 s | > 0, ≤ 30 s |
| `RABBITMQ_CHANNEL_RPC_TIMEOUT` | 5 s | > 0, ≤ 30 s, не больше I/O timeout |
| `RABBITMQ_HEARTBEAT` | 10 s | integer 1–60 s |
| `RABBITMQ_READ_TIMEOUT`, `RABBITMQ_WRITE_TIMEOUT` | 25 s | > 2 × heartbeat, ≤ 120 s |
| `RABBITMQ_CONFIRM_TIMEOUT` | 5 s | > 0, ≤ 30 s |
| `RABBITMQ_CONSUMER_POLL_TIMEOUT` | 1 s | > 0, ≤ 30 s |

Compose передаёт PHP host `rabbitmq` и внутренний порт 5672. Для native-запуска [.env.example](../../../.env.example) использует `127.0.0.1` и опубликованный порт; реальный `.env` не требуется контейнерам. Общая [DI-конфигурация](../../../config/container.php) лениво подключает topology, publisher и receiver для web и console. Bootstrap и получение адаптера из контейнера не открывают соединение. Отсутствующая конфигурация даёт безопасный `configuration_invalid` только при запросе транспортной зависимости. Каждый запрос `IBrokerReceiver` создаёт новый экземпляр; его владелец закрывает receiver в `finally`.

[`DeclareMessagingTopologyHandler`](../../../modules/platform/application/handler/DeclareMessagingTopologyHandler.php) вызывает только `IBrokerTopology`. Тонкий [console controller](../../../modules/platform/presentation/console/MessagingController.php) предоставляет `platform-messaging/declare`: успех возвращает exit code 0, отказ — ненулевой код и безопасную машинную причину. Команда не применяет policy, не публикует сообщения и не запускает consumer. Обычный bootstrap не объявляет топологию и не запускает worker.

Policy и AMQP-декларация выполняются отдельно в запущенном локальном окружении. GNU Make предоставляет короткие команды:

```bash
make rabbitmq-policy
make rabbitmq-topology
make rabbitmq-check
```

Прямой контейнерный эквивалент:

```bash
docker compose exec -T rabbitmq su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh
docker compose exec -T php-fpm php yii platform-messaging/declare
docker compose exec -T rabbitmq su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh --check
```

`rabbitmq-check` только читает broker state: проверяет оба exchanges, quorum queues, bindings и эффективную DLX policy, а не только работоспособность процесса. Отсутствие или расхождение топологии даёт ненулевой exit code без её исправления. `BROKER_SERVICE=rabbitmq-test` выбирает изолированный брокер только для `rabbitmq-policy` и `rabbitmq-check`; допустимы лишь `rabbitmq` и `rabbitmq-test`. `rabbitmq-topology` всегда использует конфигурацию приложения, а не этот параметр. Эти команды не удаляют объекты и не очищают очереди.

Изолированный брокер запускается только с profile `messaging-test`, использует vhost `ideakit_transport_test`, синтетические credentials и tmpfs вместо рабочего volume. Его host-порт публикуется на `127.0.0.1:${TEST_RABBITMQ_PORT:-5673}`; контейнерные тесты используют `rabbitmq-test:5672`. Test configuration требует `APP_ENV=test` и явные `TEST_RABBITMQ_HOST`, `PORT`, `USER`, `PASSWORD`, `VHOST`, не подставляя рабочие credentials. Fixtures работают только с выделенным vhost и своими ресурсами; рабочие очереди не очищаются.

Проверенные команды подготовки и запуска транспортных тестов:

```bash
docker compose --profile messaging-test up -d --wait rabbitmq-test postgres
docker compose exec -T rabbitmq-test su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/platform --no-colors
docker compose exec -T -e APP_ENV=test -e TEST_RABBITMQ_HOST=rabbitmq-test -e TEST_RABBITMQ_PORT=5672 -e TEST_RABBITMQ_USER=transport-test -e TEST_RABBITMQ_PASSWORD=local-transport-test-only -e TEST_RABBITMQ_VHOST=ideakit_transport_test php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure/rabbitmq --no-colors
```

Перед PHP-проверками образ должен быть пересобран через `docker compose build php-fpm`: исходники не монтируются с хоста. [Configuration unit-тест](../../../tests/unit/modules/platform/infrastructure/rabbitmq/RabbitMqConnectionConfigTest.php) проверяет параметры и безопасные ошибки. [Broker integration-тест](../../../tests/integration/modules/platform/infrastructure/rabbitmq/RabbitMqTransportTest.php) подтверждает повторную декларацию, сохранность сообщения, оба routing paths и отказ без удаления несовместимого exchange. Тесты выполняются последовательно; недоступный broker является ошибкой, а не skip.

`make test-rabbitmq` проверяет Compose, пересобирает PHP-образ, запускает PHP и отдельный test broker с ожиданием health-checks, применяет только test policy и выполняет целевые транспортные integration-тесты. Фикстура сама объявляет и удаляет свои тестовые AMQP-объекты; заранее объявлять их через рабочую console-команду не нужно. Цель не публикует smoke-сообщение в рабочую `critical`.

Существующий [CI test job](../../../.github/workflows/ci_cd_pipeline.yml) подготавливает тот же test broker и policy, затем передаёт явные `TEST_RABBITMQ_*` для host runner. Проверка PHP 8.1 сохранена; добавлены требуемые extensions. Deployment-логика не изменена. Выполнение workflow в GitHub не заменяется локальным контейнерным прогоном.

## RabbitMQ: подтверждённая публикация

[`IBrokerPublisher`](../../../modules/platform/application/port/IBrokerPublisher.php) принимает неизменяемый `BrokerEnvelope` и возвращает `BrokerPublishReceipt` только с подтверждённым outbox UUID. Envelope содержит outbox UUID, тип и версию команды, correlation UUID и существующий `TelegramUpdateReceivedPayload` с внутренним UUID update. Writer и его PostgreSQL-транзакция не меняются; publisher не обращается к БД.

[`BrokerEnvelopeCodec`](../../../modules/platform/infrastructure/rabbitmq/BrokerEnvelopeCodec.php) сериализует ровно `outbox_id`, `message_type`, `schema_version`, `correlation_id`, `payload`; payload содержит только `update_id`. UUID канонические, типы проверяются без преобразования, неизвестные поля отклоняются. Ограничения: payload ≤ 1024 байт, envelope ≤ 4096 байт, JSON depth ≤ 16. Malformed JSON, усечённое тело и несовпадение AMQP properties дают `invalid_envelope`. AMQP `message_id` и `correlation_id` совпадают с envelope; `content_type=application/json`, `delivery_mode=2`. Из headers допускаются только неотрицательные целочисленные quorum counters `x-delivery-count` и `x-acquired-count`; они не входят в envelope. Codec проверяет техническую структуру, а не разрешение типа/версии для dispatch; прикладной registry ещё не реализован.

[`RabbitMqPublisher`](../../../modules/platform/infrastructure/rabbitmq/RabbitMqPublisher.php) открывает собственные connection/channel для одной публикации в `ideakit.commands` с ключом `critical`, включает confirms и отправляет persistent message с `mandatory=true`. Успех требует ack без `basic.return`. Return вместе с ack даёт `unroutable`; nack — `nacked`, истечение ограниченного ожидания — `confirm_timeout`, сетевой сбой — `connection_failure`. Публичные исключения не содержат библиотечных деталей или цепочки исходного исключения. Channel и connection закрываются в `finally`; ошибка закрытия не подменяет первоначальный отказ. Конструктор не выполняет I/O.

Автоматических повторов в publisher нет. Два явных вызова с тем же outbox UUID могут создать две доставки с одинаковым `message_id`: это at-least-once, не exactly-once. Confirm доказывает принятие брокером, но не выполнение команды. При потере подтверждения результат может быть неоднозначным; повтор публикации принадлежит relay, идемпотентная обработка — будущему worker.

Publisher не обращается к PostgreSQL и не выполняет Telegram update. Постоянный worker, registry и диспетчеризация прикладных обработчиков относятся к #43-5.

[Codec unit-тест](../../../tests/unit/modules/platform/infrastructure/rabbitmq/BrokerEnvelopeCodecTest.php) проверяет wire contract и границы. [Confirmation unit-тест](../../../tests/unit/modules/platform/infrastructure/rabbitmq/PublishConfirmationTest.php) проверяет ack, return+ack, nack, потерю соединения и конечный deadline без продления входящими событиями. Broker integration-тест дополнительно проверяет реальную публикацию и её properties, два явных вызова без скрытого повтора, unroutable return+ack и закрытый локальный порт.

Publisher использует [ограниченный StreamIO](../../../modules/platform/infrastructure/rabbitmq/PublisherStreamIo.php) для одного сетевого цикла, включая handshake, публикацию и закрытие. Чтение content frames в `basic.return` сохраняет deadline ожидания confirm, даже когда библиотека передаёт нулевой timeout. [Тест deadline](../../../tests/unit/modules/platform/infrastructure/rabbitmq/PublisherDeadlineTest.php) проверяет это чтение, восстановление общего deadline и остановку заблокированной записи.

## RabbitMQ: получение и ручное подтверждение

[`IBrokerReceiver`](../../../modules/platform/application/port/IBrokerReceiver.php) предоставляет `receive(timeoutSeconds)` и `close()`. [`IBrokerDelivery`](../../../modules/platform/application/port/IBrokerDelivery.php) возвращает типизированный envelope через `message()` и позволяет явно вызвать `acknowledge()` или окончательный `reject()`. Библиотечное сообщение, channel, delivery tag и broker metadata остаются в Infrastructure.

[`RabbitMqReceiver`](../../../modules/platform/infrastructure/rabbitmq/RabbitMqReceiver.php) лениво открывает собственные connection/channel и подписывается на `critical` через `basic_consume` с manual ack и per-consumer `prefetch=1`. До подтверждения или отклонения первой доставки второй envelope не поступает этому consumer. `receive()` возвращает `null` только после штатного ожидания без доставки; сетевой отказ даёт `connection_failure`. Timeout ожидания и интервал polling ограничены значениями > 0 и ≤ 30 секунд; monotonic deadline не продлевается входящими событиями. Подключение и настройка channel используют отдельные конечные лимиты конфигурации. Конструктор не выполняет I/O.

Владелец receiver вызывает `close()` в `finally`; после закрытия нужен новый receiver. Закрытие до ack допускает повторную доставку того же outbox UUID. [`RabbitMqDelivery`](../../../modules/platform/infrastructure/rabbitmq/RabbitMqDelivery.php) подтверждает только свой tag без `multiple`; `reject()` не делает requeue. Повторное завершение доставки даёт `delivery_already_settled`, подтверждение или отклонение незавершённой доставки закрытого channel — `delivery_unavailable`. Ошибка отправки ack/reject закрывает повреждённый receiver и возвращает безопасный `connection_failure`, не отмечая доставку успешно завершённой. Ack не заменяет идемпотентность будущего обработчика при неоднозначном сетевом исходе.

Channel ограничивает тело 4096 байтами. Для malformed или oversized сообщения `message()` возвращает `invalid_envelope`, но доставка остаётся доступной для окончательного `reject()`. Отклонённое сообщение поступает в `critical.failed` с сохранённым телом и broker death metadata; автоматического обратного маршрута нет. При временном отсутствии error binding policy удерживает сообщение до успешной повторной DLX-доставки. Transport не выполняет обработчики и не принимает прикладное решение для неизвестного типа/версии.

[Broker integration-тест](../../../tests/integration/modules/platform/infrastructure/rabbitmq/RabbitMqTransportTest.php) проверяет manual ack, prefetch, redelivery после закрытия, запрет повторного settlement, DLX и восстановление error route с конечным ожиданием broker retry. Malformed/oversized сообщения проверяются на реальном брокере без дублирования всех codec-границ. [Delivery unit-тест](../../../tests/unit/modules/platform/infrastructure/rabbitmq/RabbitMqDeliveryTest.php) детерминированно проверяет ошибку отправки ack/reject без утечки библиотечного контекста. Worker, прикладные retries и registry обработчиков остаются следующими задачами.

## Outbox relay

[`RelayOutboxHandler`](../../../modules/platform/application/handler/RelayOutboxHandler.php) выполняет ограниченную команду `RelayOutboxCommand`. Единственный маршрут остаётся `telegram.update.received/1.0` → `critical`; другие модули и таблицы не читаются. [`DbOutboxRelayStore`](../../../modules/platform/infrastructure/db/DbOutboxRelayStore.php) использует тот же Yii `db`, что writer. Активная внешняя Yii/PDO-транзакция запрещает запуск relay.

Store захватывает по одной готовой записи `RABBITMQ` через `FOR UPDATE SKIP LOCKED`, устанавливает уникальный token, lease и начатую попытку. Commit захвата завершается до сетевого вызова. Владение проверяется перед публикацией и при отдельной атомарной фиксации; истёкший lease или другой token запрещают запоздалое обновление. Повреждённый контракт получает `FAILED` без отправки. Hash проверяется по детерминированному формату writer, а не тексту JSONB.

`DELIVERED` означает принятие сообщения RabbitMQ, не выполнение обработчика. Relay — единственный владелец повторов публикации: `connection_failure`, `nacked` и `confirm_timeout` назначают ограниченный экспоненциальный повтор с jitter, пока не исчерпан лимит. `next_attempt_at` хранится в БД; процесс не ждёт срока повтора. Неоднозначный confirm сохраняет прежние outbox UUID, correlation UUID и payload. `UNKNOWN`, replacement message и ручная отправка `FAILED` в DLQ не используются.

Операционные отказы останавливают запуск; сбой фиксации после публикации оставляет `PROCESSING` и прекращает дальнейшие захваты. Публичные ошибки содержат только [безопасный код](../../../modules/platform/application/enum/OutboxRelayError.php), без SQL, payload и исходной цепочки исключения. stdout содержит `claimed`, `delivered`, `retry_scheduled`, `failed`, `lease_lost`. Exit codes: 0 — штатный итог, 1 — операционный отказ, 2 — неверный CLI limit.

Ленивое DI не открывает сеть и не запускает relay при web/console bootstrap. [Конфигурация](../../../config/outbox_relay.php) строго разбирает пять положительных целочисленных строк; неверное значение отклоняется до claim:

| Переменная | Default | Граница |
|---|---:|---|
| `OUTBOX_RELAY_LIMIT` | 10 | 1–100 |
| `OUTBOX_RELAY_MAX_ATTEMPTS` | 5 | 1–10 |
| `OUTBOX_RELAY_LEASE_SECONDS` | 600 | 60–3600 и не меньше сетевого budget + 30 s |
| `OUTBOX_RELAY_RETRY_BASE_SECONDS` | 15 | 1–300 s |
| `OUTBOX_RELAY_RETRY_MAX_SECONDS` | 900 | от base до 3600 s |

[`RabbitMqConnectionConfig`](../../../modules/platform/infrastructure/rabbitmq/RabbitMqConnectionConfig.php) задаёт budget `4 × connectionTimeout + 3 × channelRpcTimeout + 10 × max(readTimeout, writeTimeout) + confirmTimeout`. При defaults это 282 s, минимальный lease — 312 s; при максимальных допустимых timeout settings — 1440 s и 1470 s соответственно. Это консервативный in-process бюджет, не гарантия против остановки процесса или зависания ОС/native resolver; fencing остаётся обязательным.

Ручная команда в подготовленном окружении — `docker compose exec -T php-fpm php yii platform-outbox/relay --limit=10`. Без `--limit` используется configuration default. `make outbox-relay` оборачивает эту команду без CLI override; он не включён в start, health или diagnose. Make target проверен dry-run через GNU Make в контейнере. Console route и фактический запуск проверены только на пустом тестовом outbox:

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm php yii help platform-outbox/relay
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm php yii platform-outbox/relay
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm php yii platform-outbox/relay --limit=1
```

До #43-6 зависший `PROCESSING`, включая истёкший lease, не восстанавливается: повторный запуск этого relay его не подхватит. Также не выбираются `TELEGRAM`, `DELIVERED` и `FAILED`. Scheduler, cleanup и recovery command не реализованы; постоянный consumer и прикладная идемпотентность относятся к #43-5. Готовность relay не означает готовность Telegram-бота.

Проверки: [config unit-тест](../../../tests/unit/modules/platform/infrastructure/OutboxRelayConfigTest.php), [DI/console](../../../tests/integration/config/PlatformOutboxRelayContainerBindingsTest.php), [PostgreSQL store](../../../tests/integration/modules/platform/infrastructure/DbOutboxRelayStoreTest.php), [relay integration](../../../tests/integration/modules/platform/infrastructure/OutboxRelayIntegrationTest.php) и [сквозной PG/RabbitMQ тест](../../../tests/integration/modules/platform/infrastructure/OutboxRelayRabbitMqTest.php). Они подтверждают commit до публикации, rollback, fencing, ограниченные повторы с прежним ID, остановку после сбоя фиксации, `DELIVERED` по confirm и `FAILED` по return. Сквозной тест находится вне transport-only suite `make test-rabbitmq` и требует подготовленной `ideakit_test` и явного test vhost:

```bash
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e TEST_RABBITMQ_HOST=rabbitmq-test -e TEST_RABBITMQ_PORT=5672 -e TEST_RABBITMQ_USER=transport-test -e TEST_RABBITMQ_PASSWORD=local-transport-test-only -e TEST_RABBITMQ_VHOST=ideakit_transport_test php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure/OutboxRelayRabbitMqTest.php --no-colors
```

## Проверка

Структура, ограничения и индексы проверяются [schema-тестом](../../../tests/integration/modules/platform/infrastructure/OutboxMessageSchemaTest.php). [Lifecycle-тест](../../../tests/integration/modules/platform/infrastructure/OutboxMigrationLifecycleTest.php) вызывает откат и повторное применение внутри откатываемой PostgreSQL-транзакции и сравнивает схемы родительских таблиц и Yii migration history. [Тест writer](../../../tests/integration/modules/platform/infrastructure/DbOutboxWriterTest.php) проверяет запись, общий commit/rollback, повторы и конкурентный конфликт в тестовой PostgreSQL.

[DI-тест](../../../tests/integration/config/PlatformOutboxContainerBindingsTest.php) подтверждает общее соединение и rollback для web- и console-конфигураций. [Архитектурный тест](../../../tests/unit/modules/platform/PlatformArchitectureTest.php) защищает публичный Application-контракт от framework/Infrastructure-зависимостей и Platform Infrastructure от импорта Telegram internals.

[Transport DI-тест](../../../tests/integration/config/PlatformBrokerContainerBindingsTest.php) проверяет ленивое разрешение web/console-конфигураций без network I/O, безопасный отказ при отсутствии настроек и результат console-команды:

```bash
docker compose exec -T php-fpm vendor/bin/codecept run integration tests/integration/config/PlatformBrokerContainerBindingsTest.php --no-colors
```

В запущенном локальном Compose-окружении с подготовленными тестовой БД и изолированным брокером проверки выполняются так:

```bash
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/platform --no-colors
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e TEST_RABBITMQ_HOST=rabbitmq-test -e TEST_RABBITMQ_PORT=5672 -e TEST_RABBITMQ_USER=transport-test -e TEST_RABBITMQ_PASSWORD=local-transport-test-only -e TEST_RABBITMQ_VHOST=ideakit_transport_test php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure --no-colors
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=false -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run integration tests/integration/config/PlatformOutboxContainerBindingsTest.php --no-colors
```

Проверка запускается только на отдельной тестовой PostgreSQL. Применение и откат миграции не выполняются автоматически при запуске приложения.
