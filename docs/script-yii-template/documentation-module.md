# Документация проекта через пакет `doctordanila/script-doc`

Для отображения проектной документации (Markdown-файлов) в веб-интерфейсе используется пакет `doctordanila/script-doc`. Он позволяет превратить папку `docs/` в читаемый сайт с навигацией.

## Контроллер `DocsController`
Класс `core\presentation\controller\DocsController` содержит метод `actionUi()`, который создаёт экземпляр `DoctorDanila\ScriptDoc\Include\Controller` и настраивает его:
```php
$doc = new DocController();
$doc->setProjectName('IDEAKIT.thescript')
    ->setDocsDir(\Yii::getAlias('@app/docs'))
    ->setRoutePrefix('/docs')
    ->setSwaggerPath('/docs/swagger');
$doc->handleRequest();
```

- `setProjectName` - имя проекта, отображаемое в заголовке.
- `setDocsDir` - путь к папке с документацией (@app/docs).
- `setRoutePrefix` - префикс URL для доступа к документации (в нашем случае /docs).
- `setSwaggerPath` - ссылка на Swagger UI (добавляется в меню).

## Маршруты
В `config/web.php` заданы:
```php
'docs' => 'docs/ui',
'docs/<page:.*>' => 'docs/ui',
```
Таким образом, все запросы к `/docs` и подпутьям обрабатываются `DocsController`, который рендерит соответствующую страницу документации.

## Структура документации
> **Примечание:** смотрите активную структуру документации проекта в [`/docs/README.md`](../README.md)
