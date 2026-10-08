# Модуль Telegram

## Реализованная часть

Подготовлены PostgreSQL-таблицы `telegram_bot_sessions` для состояния диалога и `telegram_updates`
для надёжного приёма входящих обновлений. HTTP-граница webhook проверена в тестовой композиции,
но не подключена к production. Атомарный приём inbox/outbox, управление диалогом и отправка сообщений
ещё не реализованы.

## HTTP-граница webhook

[Контроллер](../../../modules/telegram/presentation/controller/WebhookController.php) вызывает только
[IAcceptTelegramUpdate](../../../modules/telegram/application/port/IAcceptTelegramUpdate.php).
Неизменяемая команда содержит серверный bot key, числовой Telegram update ID, тип обновления,
nullable chat ID, причину игнорирования, исходный JSON, версию payload и его SHA-256.
У проигнорированного обновления chat ID остаётся `null`. Числовой update ID не является UUID inbox:
receipt содержит отдельный внутренний UUID и исход `ACCEPTED` либо `DUPLICATE`.
Только после такого receipt возвращается `200 {"ok":true}`. Контроллер не пишет в БД,
не вызывает Users или Platform writer и не повторяет приём автоматически.

[Web fragment](../../../config/telegram_webhook.php) допускает только фактический
`POST /telegram/webhook` без query string. Method override не меняет метод webhook.
Другие методы получают `405` с `Allow: POST`, а HEAD — без тела. Альтернативный Yii action URL,
дополнительные сегменты и неканонические адреса не достигают приёма. Исключение административного JWT
и отключение CSRF ограничены этим входом; другие endpoints сохраняют прежние проверки.

Настройки `TELEGRAM_BOT_KEY` и `TELEGRAM_WEBHOOK_SECRET` загружаются лениво после начальных HTTP gates.
Secret проверяется до чтения тела. Принимается JSON с UTF-8 без сжатия; фактический предел тела —
65 536 байт, независимо от Content-Length. Parser вызывается один раз, hash считается по исходным байтам.
Nginx применяет отдельный предел 64k непосредственно в точном location и не пишет его access log;
общий предел остальных маршрутов остаётся 10m.

Отказы возвращают только `error.code` и закрытый `error.message`: неверный запрос/update — 400,
secret — 403, метод — 405, размер — 413, media/encoding — 415, недоступность приёма/настроек — 503,
неожиданная ошибка — 500. Ошибки до action также защищены. Для чувствительного пути debug/Gii
исключаются до bootstrap; журнал содержит только безопасный reason, HTTP-статус и серверный correlation ID,
без body, secret и исходного исключения. Обычная dev-композиция сохраняется.

**Граница активации:** основной `config/web.php` не подключает fragment. Тестовый acceptor находится
только в tests и ничего не сохраняет. Production endpoint и JWT-исключение будут подключены вместе
с настоящим атомарным acceptor в следующем срезе #39. Этот HTTP-срез не реализует конкурентную
дедупликацию, worker, сессии, Telegram API или административную аутентификацию.

## Транспортные модели

В Infrastructure добавлены неизменяемые типы `MessageUpdate`, `CallbackQueryUpdate`,
`MyChatMemberUpdate` и `IgnoredUpdate`. Они раздельно сохраняют ID отправителя, чата и участника
события изменения статуса бота. `IgnoredUpdate` сохраняет исходный вид обновления и безопасную причину
игнорирования. Инфраструктурный parser ограничивает размер и глубину JSON, строго проверяет необходимые
поля и преобразует личные `message`, `callback_query` и `my_chat_member` в эти модели.
Для события с достоверным `update_id`, которое нельзя обработать, он возвращает `IgnoredUpdate`;
для некорректного JSON или недостоверного `update_id` — безопасную ошибку до записи в inbox.
У проигнорированного callback сохраняется только ID запроса для будущего подтверждения, не его `data`.
Данные callback в результате parser остаются непроверенными. Отдельный codec ниже может проверить
их подпись, но parser сам его не вызывает. Production-приём, обработчики сессий и исходящее подтверждение
callback пока не реализованы.

Отдельный классификатор сопоставляет текст `MessageUpdate` с `/start`, точным текстом
reply-кнопок `Следующая идея` и `Отменить` либо `OTHER_TEXT`. Он не меняет текст и не
определяет состояние диалога: обычный текст может стать комментарием только в будущем
обработчике при ожидании комментария. Проверка callback через codec также подключается
позже, после получения доверенного Telegram-профиля.

## Подписанные callback

Инфраструктурный codec создаёт и проверяет callback версии `1` в формате
`version.action.subject.signature` (не более 64 байт). Действия `d/o/p` содержат UUID карточки
в каноническом Base64URL, `c/x` — неотрицательную ревизию в строчной base36. Подпись HMAC-SHA256
привязана к доверенным `bot_key` и UUID Telegram-профиля. Codec отвергает неизвестные и
неканонические значения безопасной ошибкой, не раскрывающей входные данные.

Ключи передаются codec в каноническом стандартном Base64: текущий ключ подписывает и проверяет,
необязательный предыдущий только проверяет. Каждый ключ после декодирования должен содержать не
менее 32 байт; значения ключей не хранятся в коде модуля. В версии `1` нет срока действия:
после удаления предыдущего ключа старые кнопки становятся недействительными.

Проверенная подпись подтверждает только целостность кнопки, но не право выполнить действие.
Получение доверенного профиля, подключение codec к конфигурации приложения, проверки сессии и
бизнес-доступа, production-приём и ответ на callback остаются для следующих срезов.

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
Эта миграция не реализует Application-сценарии, HTTP endpoints, workers, Redis-ключи и внешние адаптеры.

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
Repository, сохраняющего Application-handler, workers и scheduler приёма пока нет.

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

[Functional-тест](../../../tests/functional/modules/telegram/WebhookCest.php) использует настоящий web config
и fragment с test-only acceptor: проверяет HTTP gates, receipt, отсутствие побочных эффектов,
CSRF/JWT scope и отдельные Yii-процессы для безопасных ошибок и dev bootstrap.

После пересборки application image:

```bash
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run functional tests/functional/modules/telegram/WebhookCest.php --no-colors
docker compose run --rm --no-deps -e APP_ENV=test -e APP_DEBUG=false -e 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' -e 'TEST_DB_DSN=pgsql:host=postgres;port=5432;dbname=ideakit_test' php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram --no-colors
```

Полный regression требует [явного тестового окружения Platform](../platform/README.md#явное-тестовое-окружение),
проверенных `ideakit_test` и `ideakit_transport_test`. Docker PHP 8.2.32 не подтверждает CI на PHP 8.1.

Unit-тесты транспортных моделей, parser и callback codec:

```bash
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram/infrastructure/transport/update --no-colors
docker compose exec -T php-fpm vendor/bin/codecept run unit tests/unit/modules/telegram/infrastructure/transport/callback/TelegramCallbackCodecTest.php --no-colors
```

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
