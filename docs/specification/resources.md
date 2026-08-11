# Полезные ресурсы и ссылки

## Ссылки на внешние ресурсы
| Ссылка                                                                                                    | Описание                           |
|-----------------------------------------------------------------------------------------------------------|------------------------------------|
| [yii3.yiiframework.com](https://yii3.yiiframework.com)                                                    | Сайт Yii3                          |
| [www.yiiframework.com](https://www.yiiframework.com)                                                      | Сайт Yii2                          |
| [www.yiiframework.com/doc/guide/2.0/ru](https://www.yiiframework.com/doc/guide/2.0/ru)                    | **Документация Yii2**              |
| [www.php.net/manual/ru/](https://www.php.net/manual/ru/)                                                  | **Документация PHP**               |
| [php-psr.ru/proposed/phpdoc/](https://php-psr.ru/proposed/phpdoc/)                                        | **Стандарт кодирования PSR**       |
| [www.plantuml.com/plantuml/uml](https://www.plantuml.com/plantuml/uml/)                                   | **Генератор UML диаграм**          |
| [getcomposer.org](https://getcomposer.org/)                                                               | **Пакетный менеджер PHP**          |
| [getcomposer.org/doc/](https://getcomposer.org/doc/)                                                      | Документация пакетного менеджера   |
| [api.taskflow.thescript.agency/docs](https://api.taskflow.thescript.agency/docs)                          | Пример ведения документации        |
| [packagist.org/packages/doctordanila/script-doc](https://packagist.org/packages/doctordanila/script-doc)  | Пакет отображения документации     |
| [packagist.org/packages/doctordanila/scancore](https://packagist.org/packages/doctordanila/scancore)      | Пакет ручного статического анализа |

## Полезные команды

### Статический анализ (PHPStan)
| Команда                  | Описание                                                                      |
|--------------------------|-------------------------------------------------------------------------------|
| `composer unclestan`     | Запуск PHPStan с уровнем строгости, указанным в конфигурации (по умолчанию 5) |
| `composer unclestan:6`   | Анализ с уровнем 6 (используется для ветки dev/main)                          |
| `composer unclestan:7`   | Анализ с уровнем 7 (повышенная строгость)                                     |
| `composer unclestan:8`   | Анализ с уровнем 8 (высокая строгость)                                        |
| `composer unclestan:max` | Анализ с максимальным уровнем (для поиска всех возможных ошибок)              |

### Линтинг кода (PHP-CS-Fixer)
| Команда               | Описание                                                                 |
|-----------------------|--------------------------------------------------------------------------|
| `composer lint`       | Проверка соответствия кода стандартам без внесения изменений (dry-run)   |
| `composer lint-fix`   | Автоматическое исправление найденных нарушений                           |

### Тестирование (Codeception)
| Команда               | Описание                                                                 |
|-----------------------|--------------------------------------------------------------------------|
| `composer test`       | Запуск всех тестов (unit, api и др.)                                     |
| `composer test:unit`  | Запуск только unit-тестов                                                |
| `composer test:api`   | Запуск только API-тестов                                                 |


### Консольные команды Yii2
Команды выполняются из корня проекта через скрипт `yii` (например, `php yii <command>`).

| Команда                    | Описание                                 |
|----------------------------|------------------------------------------|
| `php yii`	                 | Список всех доступных команд             |
| `php yii help <command>`	  | Помощь по конкретной команде             |
| `php yii serve`	           | Запуск встроенного PHP-сервера           |
| `php yii migrate`	         | Применение миграций БД                   |
| `php yii migrate/down`	    | Откат последней миграции                 |
| `php yii migrate/create`	  | Создание новой миграции                  |
| `php yii cache/flush`	     | Очистка кэша приложения                  |
| `php yii cache/flush-all`	 | Очистка всех кэшей                       |
| `php yii gii`	             | Справка по Gii                           |
| `php yii gii/model`	       | Генерация модели из таблицы              |
| `php yii gii/controller`	  | Генерация контроллера                    |
| `php yii gii/crud`	        | Генерация CRUD-интерфейса                |
| `php yii gii/module`	      | Генерация модуля                         |
| `php yii gii/form`	        | Генерация формы                          |
| `php yii gii/extension`	   | Генерация расширения                     |
| `php yii message`	         | Извлечение сообщений для перевода (i18n) |
| `php yii asset`	           | Сборка и сжатие JS/CSS ассетов           |
| `php yii fixture`	         | Загрузка/выгрузка тестовых фикстур       |

### Команды Composer
| Команда                                      | Описание                                           |
|----------------------------------------------|----------------------------------------------------|
| `composer init`                              | 	Создание нового composer.json                     |
| `composer require <package>`                 | 	Добавление зависимости                            |
| `composer require <package> --dev`           | 	Добавление dev-зависимости                        |
| `composer require <package> --prefer-dist`   | 	Установка релизной версии (не исходников)         |
| `composer require <package> --prefer-stable` | 	Установка стабильной версии                       |
| `composer require <package> --no-update`     | 	Добавление без обновления composer.lock           |
| `composer update`                            | 	Обновление всех зависимостей до актуальных версий |
| `composer update <package>`                  | 	Обновление конкретного пакета                     |
| `composer update --dev <package>`            | 	Обновление dev-зависимости                        |
| `composer update --lock`                     | 	Обновление composer.lock без изменения версий     |
| `composer remove <package>`                  | 	Удаление зависимости                              |
| `composer install`                           | 	Установка зависимостей согласно composer.lock     |
| `composer install --dev`                     | 	Установка только dev-зависимостей                 |
| `composer clear-cache`                       | 	Очистка кэша Composer                             |
| `composer dump-autoload`                     | 	Перегенерация автозагрузчика                      |
| `composer prune`                             | 	Удаление неиспользуемых пакетов                   |
| `composer show`                              | 	Список установленных пакетов                      |
| `composer show --installed`                  | 	Показать установленные зависимости                |
| `composer show --tree`                       | 	Дерево зависимостей                               |
| `composer search <query>`                    | 	Поиск пакетов                                     |
| `composer config`                            | 	Просмотр/установка настроек                       |
| `composer config --list`                     | 	Список всех настроек                              |
| `composer diagnose`                          | 	Диагностика Composer                              |
| `composer self-update`                       | 	Обновление самого Composer                        |

