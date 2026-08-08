# Конфигурация приложения

## Переменные окружения (`.env`)

Шаблон использует библиотеку `vlucas/phpdotenv` для загрузки переменных из файла `.env`. Пример заполнения приведён в `.env.example`.

Основные переменные:

```dotenv
# -------------------- Application --------------------
APP_NAME="name"                 # Имя приложения
APP_ENV=dev                     # dev / prod
APP_DEBUG=true                  # true / false
DEBUG_LVL=0                     # Уровень детализации ошибок: 0 - только Yii2 debug, 1 - trace, 2 - request, 3 - всё
APP_URL="http://localhost:8080" # Базовый URL
COOKIE_VALIDATION_KEY="random-string" # Ключ для валидации кук
ADMIN_EMAIL="email"

# -------------------- FRONTEND --------------------
FRONTEND_URL="string"

# -------------------- Email --------------------
MAILER_DSN="SCHEME://USERNAME:PASSWORD@HOST:PORT"
MAILER_SCHEME="SCHEME"
MAILER_HOST="HOST"
MAILER_PORT=PORT
MAILER_USERNAME="USERNAME"
MAILER_PASSWORD="PASSWORD"
MAILER_ENCRYPTION="ssl"
SENDER_EMAIL="EMAIL"
SENDER_NAME="NAME"

# -------------------- Database --------------------
DB_DSN="pgsql:host=localhost;port=5432;dbname=crm"
DB_USERNAME="postgres"
DB_PASSWORD="secret"
```

Переменные `YII_DEBUG` и `YII_ENV` определяются в `web/index.php` и `yii` на основе `APP_DEBUG` и `APP_ENV`.

## Конфигурационные файлы

### **`config/web.php` - основная конфигурация веб-приложения**
- **Идентификатор приложения:** `$_ENV['APP_NAME']`.
- **Пространство имён контроллеров:** `core\presentation\controller`.
- **Алиасы:** `@core`, `@modules`, `@bower`, `@npm`.
- **Компоненты:**
  - `request` - парсинг JSON, ключ валидации кук.
  - `response` - формат JSON по умолчанию.
  - `user` - идентификатор `YiiIdentity`, сессии отключены.
  - `errorHandler` - `JsonErrorHandler` (кастомный).
  - `mailer` - `yii\symfonymailer\Mailer` (по умолчанию в файл).
  - `log` - запись ошибок и предупреждений, исключая 404.
  - `db` - подключение из `db.php`.
  - `urlManager` - человеко-понятные URL, правила маршрутизации (включая Swagger и документацию).
- **Модули** - подключаются из `config/modules.php`.
- **Событие `beforeRequest`** - проверяет текущий маршрут на игнорирование (аутентификация не требуется) на основе `config/ignore_routes.php`.
- **Режим разработки** - при `YII_ENV_DEV` подключаются модули `debug` и `gii`.

### **`config/console.php` - конфигурация консольного приложения**
- Идентификатор: `APP_NAME` + `-console`.
- Контроллеры: `app\commands`.
- Компоненты: `cache` (FileCache), `log` (FileTarget), `db`.
- В режиме разработки подключаются `gii` и `debug` (для консоли).
- Карта контроллеров: `migrate` с пространствами имён миграций из `migration_namespaces.php`.

### `config/db.php` - настройки базы данных
Возвращает массив с подключением, используя переменные `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`.

### `config/test.php` - конфигурация для тестов (Codeception)
- Идентификатор: `APP_NAME` + `-tests`.
- Компоненты: `db` из `test_db.php` (по умолчанию MySQL), `mailer` с `useFileTransport=true`, `request` с отключённой CSRF.

### `config/ignore_routes.php` - маршруты, не требующие аутентификации
Возвращает массив с разделами:
- `exact` - точное совпадение маршрута.
- `startsWith` - префикс.
- `regex` - регулярное выражение.
Используется в `web.php` в событии `beforeRequest`.

### `config/modules.php` - список модулей приложения
Возвращает массив конфигураций модулей. Каждый модуль может иметь свой `class` и `controllerNamespace`.

### `config/params.php` - параметры (email отправителя, администратор)
Используются в компонентах почты и в коде.


