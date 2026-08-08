# Интеграция Swagger (OpenAPI)
В шаблоне реализована автоматическая генерация документации API на основе аннотаций в коде (в стиле `@OA`). Используется библиотека `zircote/swagger-php`.

## Контроллеры
### `SwaggerController`
- `actionJson()` - сканирует директории `@core` и `@modules` на наличие аннотаций `@OA`, генерирует объект `OpenApi` и возвращает его в формате JSON.
- `actionUi()` - рендерит представление `@core/presentation/view/swagger/ui.php`, которое загружает Swagger UI с указанием URL для JSON (`/docs/swagger/json`).

Маршруты заданы в `config/web.php`:
```php
'docs/swagger/json' => 'swagger/json',
'docs/swagger'      => 'swagger/ui',
```

## Swagger UI стили
Кастомные стили расположены в `web/css/swagger.css` и применяются к интерфейсу Swagger UI (тёмная тема, адаптированная под фирменный стиль).

## Аннотации
Все аннотации собраны в папке `core/presentation/swagger/`:
- `info.php` - определяет заголовок, версию, контакт, серверы и схему безопасности (bearerAuth).
- `parameters.php` - общие параметры запросов: `PageParam`, `LimitParam`, `ExpandParam`, `FieldsParam`.
- `schemas.php` - схемы ответов: `Collection`, `Item`, `Error`.

Эти файлы подключаются через `__include.php`, который в свою очередь может быть включён в любом другом файле с аннотациями или просто просканирован генератором.

## Пример использования в контроллере
```php
/**
 * @OA\Get(
 *     path="/api/users",
 *     summary="Список пользователей",
 *     @OA\Parameter(ref="#/components/parameters/PageParam"),
 *     @OA\Parameter(ref="#/components/parameters/LimitParam"),
 *     @OA\Response(
 *         response=200,
 *         description="Успешный ответ",
 *         @OA\JsonContent(ref="#/components/schemas/Collection")
 *     )
 * )
 */
public function actionIndex()
{
    // ...
}

или

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'name',
    description: 'description'
)]
final class UserController extends BaseController
{
     #[OA\Get(
        path: '/path',
        description: 'descr',
        summary: 'example: Получить данные текущего пользователя',
        security: [['bearerAuth' => []]],
        tags: ['name'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный запрос',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'item',
                            ref: '#/components/schemas/User'
                        )
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Требуется авторизация',
                content: new OA\JsonContent(ref: '#/components/schemas/Error')
            ),
            new OA\Response(
                response: 404,
                description: 'Пользователь не найден',
                content: new OA\JsonContent(ref: '#/components/schemas/Error')
            )
        ]
    )]
    public function actionMe(): ItemDto
    {
        //...
    }
}
```
Генератор автоматически подхватит эти аннотации и включит в спецификацию.
