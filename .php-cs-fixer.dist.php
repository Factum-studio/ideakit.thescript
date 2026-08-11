<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('tests')
    ->exclude('runtime')
    ->exclude('web')
    ->exclude('.github')
    ->exclude('docs')
    ->notPath('web/index-test.php')
    ->notPath('yii')
    ->notPath('yii.bat')
    ->notPath('.gitkeep')
    ->notPath('phpstan.neon')
    ->notPath('phpstan-bootstrap.php')
    ->notPath('requirements.php')
    ->notPath('Vagrantfile')
    ->notPath('LICENSE.md')
    ->notPath('README.md')
    ->notPath('scancore_output.txt')
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,                      // Основной набор правил PSR-12
        'indentation_type' => true,            // Использовать табуляцию
        'declare_strict_types' => true,        // Добавлять declare(strict_types=1)
        'blank_line_after_opening_tag' => true,// Пустая строка после <?php
        'blank_line_after_namespace' => true,  // Пустая строка после namespace
        'single_line_after_imports' => true,    // Пустая строка после импортов
        'trailing_comma_in_multiline' => [     // Запятая в многострочных массивах/параметрах
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        //'no_unused_imports' => true,           // Удаление неиспользуемых use
        //'single_quote' => true,                // Одинарные кавычки для строк
        'array_syntax' => ['syntax' => 'short'], // Короткий синтаксис массивов
        'phpdoc_align' => ['align' => 'left'], // Выравнивание тегов @param, @return (опционально)
    ])
    ->setFinder($finder);
