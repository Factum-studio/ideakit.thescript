<?php

declare(strict_types=1);
/**
 * @OA\Schema(
 *     schema="Collection",
 *     required={"items"},
 *     @OA\Property(
 *         property="items",
 *         type="array",
 *         @OA\Items(type="object"),
 *         description="Массив элементов коллекции"
 *     ),
 *     @OA\Property(
 *         property="_meta",
 *         type="object",
 *         description="Мета-информация о пагинации",
 *         @OA\Property(
 *             property="total",
 *             type="integer",
 *             description="Общее количество элементов",
 *             example=100
 *         ),
 *         @OA\Property(
 *             property="page",
 *             type="integer",
 *             description="Текущая страница",
 *             example=1
 *         ),
 *         @OA\Property(
 *             property="limit",
 *             type="integer",
 *             description="Количество элементов на странице",
 *             example=20
 *         ),
 *         @OA\Property(
 *             property="pages",
 *             type="integer",
 *             description="Общее количество страниц",
 *             example=5,
 *             nullable=true
 *         )
 *     )
 * )
 * @OA\Schema(
 *     schema="Item",
 *     @OA\Property(property="item", type="object")
 * )
 * @OA\Schema(
 *     schema="Error",
 *     @OA\Property(property="error", type="object",
 *         @OA\Property(property="code", type="integer"),
 *         @OA\Property(property="message", type="string"),
 *         @OA\Property(property="details", type="object", additionalProperties=true)
 *     )
 * )
 */
class MainSchemasDefinitions
{
}
