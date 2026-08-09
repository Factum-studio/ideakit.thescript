<?php

declare(strict_types=1);

namespace core\presentation\controller;

use yii\web\Controller as YiiController;
use DoctorDanila\ScriptDoc\Include\Controller as DocController;

class DocsController extends YiiController
{
    public function actionUi()
    {
        $doc = new DocController();
        $doc->setProjectName('IDEAKIT.thescript')
            ->setDocsDir(\Yii::getAlias('@app/docs'))
            ->setRoutePrefix('/docs')
            ->setSwaggerPath('/docs/swagger');

        $doc->handleRequest();
    }
}
