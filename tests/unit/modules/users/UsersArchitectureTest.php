<?php

declare(strict_types=1);

namespace tests\unit\modules\users;

use Codeception\Test\Unit;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class UsersArchitectureTest extends Unit
{
    private const ALLOWED_CORE_REFERENCE = 'core\\domain\\valueObject\\UserIdentityId';

    public function testModuleDependencyBoundaries(): void
    {
        $root = dirname(__DIR__, 4);
        $violations = [
            ...$this->innerLayerViolations(
                $root,
                $root . '/modules/users/domain',
                [
                    'yii\\',
                    'modules\\users\\application\\',
                    'modules\\users\\infrastructure\\',
                    'modules\\users\\presentation\\',
                ],
            ),
            ...$this->innerLayerViolations(
                $root,
                $root . '/modules/users/application',
                [
                    'yii\\',
                    'modules\\users\\infrastructure\\',
                    'modules\\users\\presentation\\',
                ],
            ),
            ...$this->forbiddenReferenceViolations(
                $root,
                $root . '/core',
                ['modules\\users\\'],
            ),
        ];

        sort($violations, SORT_STRING);

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * @param list<string> $forbiddenPrefixes
     *
     * @return list<string>
     */
    private function innerLayerViolations(
        string $root,
        string $directory,
        array $forbiddenPrefixes,
    ): array {
        $violations = $this->forbiddenReferenceViolations(
            $root,
            $directory,
            $forbiddenPrefixes,
        );

        foreach ($this->phpFiles($directory) as $file) {
            foreach ($this->referencedNames($file) as $reference) {
                if (
                    str_starts_with($reference, 'core\\')
                    && $reference !== self::ALLOWED_CORE_REFERENCE
                ) {
                    $violations[] = $this->violation($root, $file, $reference);
                }

                if ($reference === 'Yii' || $reference === 'ActiveRecord') {
                    $violations[] = $this->violation($root, $file, $reference);
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param list<string> $forbiddenPrefixes
     *
     * @return list<string>
     */
    private function forbiddenReferenceViolations(
        string $root,
        string $directory,
        array $forbiddenPrefixes,
    ): array {
        $violations = [];

        foreach ($this->phpFiles($directory) as $file) {
            foreach ($this->referencedNames($file) as $reference) {
                foreach ($forbiddenPrefixes as $prefix) {
                    if (str_starts_with($reference, $prefix)) {
                        $violations[] = $this->violation($root, $file, $reference);
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function referencedNames(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Unable to read PHP source file.');
        }

        $references = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $references[] = ltrim($token[1], '\\');
            } elseif ($token[0] === T_STRING && in_array($token[1], ['Yii', 'ActiveRecord'], true)) {
                $references[] = $token[1];
            }
        }

        return array_values(array_unique($references));
    }

    private function violation(string $root, string $file, string $reference): string
    {
        $relativeFile = str_replace('\\', '/', substr($file, strlen($root) + 1));

        return $relativeFile . ': forbidden dependency ' . $reference;
    }
}
