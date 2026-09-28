<?php

declare(strict_types=1);

namespace modules\platform\presentation\console;

use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\handler\DeclareMessagingTopologyHandler;
use yii\base\Module;
use yii\console\Controller;
use yii\console\ExitCode;

class MessagingController extends Controller
{
    /** @param array<string, mixed> $config */
    public function __construct(
        string $id,
        Module $module,
        private readonly DeclareMessagingTopologyHandler $handler,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /** Declare the command topology without publishing or consuming messages. */
    public function actionDeclare(): int
    {
        try {
            $this->handler->handle();
        } catch (BrokerTransportException $exception) {
            $this->stderr('Messaging topology declaration failed: ' . $exception->errorCode->value . ".\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Messaging topology declared.\n");

        return ExitCode::OK;
    }
}
