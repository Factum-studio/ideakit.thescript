<?php

declare(strict_types=1);

namespace tests\unit\modules\platform;

use Codeception\Test\Unit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class PlatformArchitectureTest extends Unit
{
    public function testPlatformDependenciesFollowLayerBoundaries(): void
    {
        $root = dirname(__DIR__, 4) . '/modules/platform';
        $boundaries = [
            'application' => [
                'yii\\', 'Yii', 'ActiveRecord', 'AMQP', 'PhpAmqpLib\\',
                'modules\\platform\\infrastructure\\', 'modules\\telegram\\',
            ],
            'infrastructure' => ['modules\\telegram\\'],
            'infrastructure/rabbitmq' => [
                'yii\\db\\', 'ActiveRecord', 'PDO', 'core\\', 'modules\\users\\',
                'modules\\platform\\infrastructure\\db\\',
            ],
            'presentation' => ['PhpAmqpLib\\', 'AMQP', 'modules\\platform\\infrastructure\\'],
        ];
        $violations = [];

        foreach ($boundaries as $layer => $forbiddenPrefixes) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $root . '/' . $layer,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));
            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if ($source === false) {
                    throw new RuntimeException('Unable to read PHP source file.');
                }
                foreach (token_get_all($source) as $token) {
                    if (!is_array($token) || !in_array($token[0], [
                        T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
                    ], true)) {
                        continue;
                    }
                    $reference = ltrim($token[1], '\\');
                    foreach ($forbiddenPrefixes as $prefix) {
                        if (str_starts_with($reference, $prefix)) {
                            $violations[] = $file->getFilename() . ': forbidden dependency ' . $reference;
                        }
                    }
                }
            }
        }

        sort($violations, SORT_STRING);
        self::assertSame([], array_values(array_unique($violations)));
    }
}
