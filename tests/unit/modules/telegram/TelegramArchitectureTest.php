<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram;

use Codeception\Test\Unit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class TelegramArchitectureTest extends Unit
{
    public function testApplicationAndCoreDependencyBoundaries(): void
    {
        $root = dirname(__DIR__, 4);
        $violations = [
            ...$this->violations($root . '/modules/telegram/application', [
                'yii\\', 'modules\\telegram\\infrastructure\\', 'modules\\telegram\\presentation\\',
            ]),
            ...$this->violations($root . '/core', ['modules\\telegram\\']),
        ];
        self::assertSame([], $violations);
    }

    /**
     * @param list<string> $prefixes
     * @return list<string>
     */
    private function violations(string $directory, array $prefixes): array
    {
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                throw new RuntimeException('Unable to read PHP source file.');
            }
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_STRING && in_array($token[1], ['Yii', 'ActiveRecord'], true)
                    && str_ends_with($directory, '/application')) {
                    $violations[] = $file->getFilename() . ': ' . $token[1];
                }
                if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    continue;
                }
                foreach ($prefixes as $prefix) {
                    if (str_starts_with(strtolower(ltrim($token[1], '\\')), strtolower($prefix))) {
                        $violations[] = $file->getFilename() . ': ' . $token[1];
                    }
                }
            }
        }
        return $violations;
    }
}
