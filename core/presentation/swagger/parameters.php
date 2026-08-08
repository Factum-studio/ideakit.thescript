<?php
/**
 * @OA\Parameter(
 *     parameter="PageParam",
 *     name="page",
 *     in="query",
 *     description="Номер страницы",
 *     @OA\Schema(type="integer", default=1)
 * )
 * @OA\Parameter(
 *     parameter="LimitParam",
 *     name="limit",
 *     in="query",
 *     description="Количество элементов на странице",
 *     @OA\Schema(type="integer", default=20, maximum=1000)
 * )
 * @OA\Parameter(
 *     parameter="ExpandParam",
 *     name="expand",
 *     in="query",
 *     description="Добавляет связанные данные(таблицы) к запросу (Пример: expand=user,contact)",
 *     @OA\Schema(type="string")
 * )
 * @OA\Parameter(
 *     parameter="FieldsParam",
 *     name="fields",
 *     in="query",
 *     description="Ограничение выдачи полей данных(записей) (Пример: fields=id -> отдаёт только id записей)",
 *     @OA\Schema(type="string")
 * )
 */
class MainParamDefinitions {}