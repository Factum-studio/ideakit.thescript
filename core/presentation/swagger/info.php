<?php
/**
 * @OA\Info(
 *     title="IDEAKIT.thescript",
 *     version="1.0.0",
 *     description="<PROJECT DESCRIPTION>",
 *     @OA\Contact(
 *         email="company@thescript.agency"
 *     )
 * )
 * @OA\Server(
 *     url="http://localhost:8080",
 *     description="API dev server"
 * )
 * @OA\Server(
 *     url="<PROJECT DOMEN URI>",
 *     description="API prod server"
 * )
 * @OA\OpenApi(
 *     openapi="3.0.0"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Используйте JWT токен для аутентификации"
 * ),
 */
class InfoDefinitions {}