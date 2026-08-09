<?php

declare(strict_types=1);

namespace core\presentation\controller;

use yii\web\Controller;
use yii\web\Response;
use OpenApi\Generator;

class SwaggerController extends Controller
{
    public function actionJson(): Response
    {
        $paths = [
            \Yii::getAlias('@core'),
            \Yii::getAlias('@modules'),
        ];
        $openapi = Generator::scan($paths);
        return $this->asJson($openapi);
    }

    public function actionUi(): string
    {
        \Yii::$app->response->format = Response::FORMAT_HTML;
        return $this->renderPartial('@core/presentation/view/swagger/ui', [
            'jsonUrl' => '/docs/swagger/json',
        ]);
    }
}
