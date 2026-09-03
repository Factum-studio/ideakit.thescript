<?php

namespace unit\presentation\controller;

use Codeception\Test\Unit;
use Faker\Factory;
use Faker\Generator;
use core\application\dto\ItemDto;
use core\application\dto\CollectionDto;
use core\application\dto\ErrorDto;
use core\application\dto\SuccessDto;

use InvalidArgumentException;
use Throwable;
use unit\presentation\controller\TestableBaseController as TestingClass;

class BaseControllerTest extends Unit
{
    /**
     * @var TestableBaseController
     */
    private TestableBaseController $controller;

    /**
     * @var Generator
     */
    private Generator $faker;

    protected function _before(): void
    {
        $this->controller = new TestingClass('base-controller', null);
        $this->faker = Factory::create();
    }

    protected function _after(): void
    {
        $this->controller->resetStubs();
    }

    /**
     * @test
     * @group item
     * @dataProvider itemDataProvider
     */
    public function itemShouldReturnCorrectDto($data)
    {
        $result = $this->controller->publicItem($data);

        $this->assertInstanceOf(ItemDto::class, $result);
        $this->assertEquals($data, $result->item);

        // JSON сериализация
        $json = $result->jsonSerialize();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('item', $json);
        $this->assertEquals($data, $json['item']);
    }

    /**
     * @test
     * @group collection
     * @dataProvider collectionDataProvider
     */
    public function collectionShouldReturnCorrectDto(array $items, ?int $total, ?int $page, ?int $limit)
    {
        $result = $this->controller->publicCollection($items, $total, $page, $limit);

        $this->assertInstanceOf(CollectionDto::class, $result);
        $this->assertEquals($items, $result->items);
        $this->assertEquals($total, $result->total);
        $this->assertEquals($page, $result->page);
        $this->assertEquals($limit, $result->limit);

        // JSON сериализация
        $json = $result->jsonSerialize();
        $this->assertArrayHasKey('items', $json);
        $this->assertEquals($items, $json['items']);

        if ($total !== null) {
            $this->assertArrayHasKey('_meta', $json);
            $this->assertEquals($total, $json['_meta']['total']);
            $this->assertEquals($page, $json['_meta']['page']);
            $this->assertEquals($limit, $json['_meta']['limit']);

            $expectedPages = $limit ? ceil($total / $limit) : null;
            $this->assertEquals($expectedPages, $json['_meta']['pages']);
        } else {
            $this->assertArrayNotHasKey('_meta', $json);
        }
    }

    /**
     * @test
     * @group error
     * @dataProvider errorDataProvider
     */
    public function errorShouldReturnCorrectDto(string $message, int $code, array $details)
    {
        $result = $this->controller->publicError($message, $code, $details);

        $this->assertInstanceOf(ErrorDto::class, $result);
        $this->assertEquals($message, $result->message);
        $this->assertEquals($code, $result->code);
        $this->assertEquals($details, $result->details);

        // JSON сериализация
        $json = $result->jsonSerialize();
        $this->assertArrayHasKey('error', $json);
        $this->assertEquals($code, $json['error']['code']);
        $this->assertEquals($message, $json['error']['message']);

        if (!empty($details)) {
            $this->assertArrayHasKey('details', $json['error']);
            $this->assertEquals($details, $json['error']['details']);
        } else {
            $this->assertArrayNotHasKey('details', $json['error']);
        }
    }

    /**
     * @test
     * @group error
     */
    public function errorShouldUseDefaultCodeWhenNotProvided()
    {
        $message = $this->faker->sentence();

        $result = $this->controller->publicError($message);

        $this->assertEquals(400, $result->code);
    }

    /**
     * @test
     * @group success
     * @dataProvider successDataProvider
     */
    public function successShouldReturnCorrectDto($data, string $message)
    {
        $result = $this->controller->publicSuccess($data, $message);

        $this->assertInstanceOf(SuccessDto::class, $result);
        $this->assertEquals($data, $result->data);
        $this->assertEquals($message, $result->message);

        // JSON сериализация
        $json = $result->jsonSerialize();

        if ($data === null) {
            $this->assertIsArray($json);
            $this->assertArrayHasKey('message', $json);
            $this->assertEquals($message, $json['message']);
        } elseif (is_array($data)) {
            $this->assertEquals($data, $json);
        } elseif (is_object($data) && method_exists($data, 'jsonSerialize')) {
            $this->assertEquals($data->jsonSerialize(), $json);
        } else {
            // Для скалярных данных ожидаем массив с ключом 'data'
            $this->assertIsArray($json);
            $this->assertArrayHasKey('data', $json);
            $this->assertEquals($data, $json['data']);
        }
    }

    /**
     * @test
     * @group success
     */
    public function successShouldUseDefaultMessageWhenNotProvided()
    {
        $data = $this->faker->word();

        $result = $this->controller->publicSuccess($data);

        $this->assertEquals('OK', $result->message);
    }

    /**
     * @test
     * @group parseIdRange
     * @dataProvider validIdRangeProvider
     */
    public function parseIdRangeFromPathShouldParseValidFormats(string $input, array $expected)
    {
        $result = $this->controller->publicParseIdRangeFromPath($input);

        $actual = $this->idRangeToArray($result);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @test
     * @group parseIdRange
     * @dataProvider invalidIdRangeProvider
     */
    public function parseIdRangeFromPathShouldReturnNullForInvalidInput($input)
    {
        // Для null и пустой строки метод возвращает null без исключений
        if ($input === null || trim($input) === '') {
            $this->assertNull($this->controller->publicParseIdRangeFromPath($input));
            return;
        }

        // Для любого другого невалидного ввода метод также возвращает null
        $result = $this->controller->publicParseIdRangeFromPath($input);
        $this->assertNull($result);
    }

    /**
     * @test
     * @group parseIdRange
     */
    public function parseIdRangeFromPathShouldHandleComplexRanges()
    {
        $testCases = [
            '1,2,3,4,5' => [1, 2, 3, 4, 5],
            '1:5' => [1, 2, 3, 4, 5],
            '1,2,4:7,9,10:12' => [1, 2, 4, 5, 6, 7, 9, 10, 11, 12],
            '100:105,110,115:117' => [100, 101, 102, 103, 104, 105, 110, 115, 116, 117],
            '1,3,5:7,9,11:13,15' => [1, 3, 5, 6, 7, 9, 11, 12, 13, 15],
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->controller->publicParseIdRangeFromPath($input);
            $actual = $this->idRangeToArray($result);
            $this->assertEquals($expected, $actual, "Failed for input: {$input}");
        }
    }

    /**
     * @test
     * @group userId
     */
    public function getUserIdShouldReturnStubbedValue()
    {
        $expectedId = $this->faker->numberBetween(1, 999999);
        $this->controller->setStubUserId($expectedId);

        $result = $this->controller->publicGetUserId();

        $this->assertEquals($expectedId, $result);
    }

    /**
     * @test
     * @group userId
     */
    public function getUserIdShouldReturnNullByDefault()
    {
        $result = $this->controller->publicGetUserId();
        $this->assertNull($result);
    }

    /**
     * @test
     * @group limit
     * @dataProvider limitDataProvider
     */
    public function getLimitShouldRespectBusinessRules($requested, $expected)
    {
        if ($requested !== null) {
            $this->controller->setStubRequestParam('limit', $requested);
        }

        $result = $this->controller->publicGetLimit();

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     * @group limit
     */
    public function getLimitShouldUseDefaultWhenNoParam()
    {
        $result = $this->controller->publicGetLimit();

        $this->assertEquals($this->controller->defaultPageSize, $result);
    }

    /**
     * @test
     * @group limit
     */
    public function getLimitShouldNeverExceed1000()
    {
        for ($i = 0; $i < 100; $i++) {
            $hugeLimit = $this->faker->numberBetween(1001, 1000000);
            $this->controller->setStubRequestParam('limit', $hugeLimit);

            $result = $this->controller->publicGetLimit();

            $this->assertLessThanOrEqual(1000, $result);
            $this->assertEquals(1000, $result);
        }
    }

    /**
     * @test
     * @group limit
     */
    public function getLimitShouldNeverBeLessThan1()
    {
        $invalidValues = [0, -1, -5, -10, -100, -1000];

        foreach ($invalidValues as $value) {
            $this->controller->setStubRequestParam('limit', $value);

            $result = $this->controller->publicGetLimit();

            $this->assertGreaterThanOrEqual(1, $result);
            $this->assertEquals(1, $result);
        }
    }

    /**
     * @test
     * @group page
     * @dataProvider pageDataProvider
     */
    public function getPageShouldRespectBusinessRules($requested, $expected)
    {
        if ($requested !== null) {
            $this->controller->setStubRequestParam('page', $requested);
        }

        $result = $this->controller->publicGetPage();

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     * @group page
     */
    public function getPageShouldNeverBeLessThanOne()
    {
        for ($i = 0; $i < 100; $i++) {
            $invalidPage = $this->faker->numberBetween(-1000, 0);
            $this->controller->setStubRequestParam('page', $invalidPage);

            $result = $this->controller->publicGetPage();

            $this->assertGreaterThanOrEqual(1, $result);
            $this->assertEquals(1, $result);
        }
    }

    /**
     * @test
     * @group integration
     */
    public function errorHandlingFlow()
    {
        $errorMessage = $this->faker->sentence();
        $errorCode = $this->faker->randomElement([400, 401, 403, 404, 422, 500]);
        $errorDetails = [
            'field' => $this->faker->word(),
            'errors' => $this->faker->words(3)
        ];

        $error = $this->controller->publicError($errorMessage, $errorCode, $errorDetails);

        $this->assertInstanceOf(ErrorDto::class, $error);

        $json = $error->jsonSerialize();
        $this->assertEquals($errorCode, $json['error']['code']);
        $this->assertEquals($errorMessage, $json['error']['message']);
        $this->assertEquals($errorDetails, $json['error']['details']);
    }

    public static function itemDataProvider(): array
    {
        $faker = Factory::create();

        return [
            'simple string' => [$faker->word()],
            'sentence' => [$faker->sentence()],
            'integer' => [$faker->numberBetween(1, 1000)],
            'float' => [$faker->randomFloat(2, 1, 100)],
            'boolean true' => [true],
            'boolean false' => [false],
            'null' => [null],
            'simple array' => [[$faker->word(), $faker->word()]],
            'assoc array' => [[
                'id' => $faker->numberBetween(1, 100),
                'name' => $faker->name(),
                'email' => $faker->email()
            ]],
            'object' => [(object)[
                'property' => $faker->word(),
                'value' => $faker->numberBetween(1, 100)
            ]],
        ];
    }

    public static function collectionDataProvider(): array
    {
        $faker = Factory::create();

        $generateItems = function($count) use ($faker) {
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $items[] = [
                    'id' => $faker->numberBetween(1, 10000),
                    'name' => $faker->name()
                ];
            }
            return $items;
        };

        return [
            'with all params' => [
                $generateItems(5),
                $faker->numberBetween(50, 200),
                $faker->numberBetween(2, 10),
                $faker->numberBetween(10, 50)
            ],
            'without total' => [
                $generateItems(3),
                null,
                $faker->numberBetween(1, 5),
                $faker->numberBetween(10, 30)
            ],
            'without page' => [
                $generateItems(7),
                $faker->numberBetween(100, 500),
                null,
                $faker->numberBetween(20, 60)
            ],
            'without limit' => [
                $generateItems(4),
                $faker->numberBetween(30, 150),
                $faker->numberBetween(2, 8),
                null
            ],
            'only items' => [
                $generateItems(10),
                null,
                null,
                null
            ],
            'empty items' => [
                [],
                $faker->numberBetween(0, 50),
                $faker->numberBetween(1, 3),
                $faker->numberBetween(10, 20)
            ],
        ];
    }

    public static function errorDataProvider(): array
    {
        $faker = Factory::create();

        return [
            'minimal error' => [
                $faker->sentence(),
                400,
                []
            ],
            'with simple details' => [
                $faker->sentence(),
                $faker->randomElement([401, 403, 404]),
                ['field' => $faker->word()]
            ],
            'with multiple details' => [
                $faker->sentence(),
                $faker->randomElement([422, 500]),
                [
                    'field' => $faker->word(),
                    'errors' => $faker->words(3),
                    'timestamp' => $faker->unixTime()
                ]
            ],
            'validation error' => [
                'Validation failed',
                422,
                [
                    'email' => ['Invalid format', 'Already taken'],
                    'password' => ['Too short']
                ]
            ],
            'not found error' => [
                'Resource not found',
                404,
                ['resource_id' => $faker->numberBetween(1, 1000)]
            ],
        ];
    }

    public static function successDataProvider(): array
    {
        $faker = Factory::create();

        return [
            'with data and custom message' => [
                ['id' => $faker->numberBetween(1, 100), 'status' => 'created'],
                $faker->sentence()
            ],
            'with data only' => [
                ['message' => $faker->sentence()],
                'OK'
            ],
            'with null data' => [
                null,
                $faker->sentence()
            ],
            'with scalar data' => [
                $faker->word(),
                'OK'
            ],
            'with array data' => [
                [
                    'id' => $faker->numberBetween(1, 1000),
                    'name' => $faker->name(),
                    'email' => $faker->email(),
                    'roles' => $faker->words(3)
                ],
                'User created successfully'
            ],
        ];
    }

    public static function validIdRangeProvider(): array
    {
        return [
            'single number' => ['42', [42]],
            'simple range' => ['5:10', [5,6,7,8,9,10]],
            'comma separated' => ['1,3,5,7,9', [1,3,5,7,9]],
            'mixed format' => ['1,3,5:7,9,11:13', [1,3,5,6,7,9,11,12,13]],
            'with sorting' => ['5,1,3', [1,3,5]],
            'range with single' => ['10:15,20', [10,11,12,13,14,15,20]],
            'multiple ranges' => ['1:3,7:9,11:13', [1,2,3,7,8,9,11,12,13]],
        ];
    }

    public static function invalidIdRangeProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'spaces only' => ['   '],
            'letters only' => ['abc'],
            'letters and numbers' => ['a123'],
            'negative numbers' => ['-5'],
            'range with negative' => ['-5:10'],
            'invalid separator' => ['1;2;3'],
            'double colon' => ['1::5'],
            'reversed range' => ['10:5'],
            'empty range' => [':'],
            'trailing comma' => ['1,2,3,'],
            'leading comma' => [',1,2,3'],
            'double comma' => ['1,,2,3'],
            'empty in comma' => ['1,,2'],
        ];
    }

    public static function limitDataProvider(): array
    {
        return [
            'normal limit' => [15, 15],
            'minimum limit' => [1, 1],
            'maximum limit' => [1000, 1000],
            'below minimum' => [0, 1],
            'negative' => [-5, 1],
            'above maximum' => [1500, 1000],
            'string number' => ['25', 25],
            'null' => [null, 20],
        ];
    }

    public static function pageDataProvider(): array
    {
        return [
            'normal page' => [5, 5],
            'first page' => [1, 1],
            'zero' => [0, 1],
            'negative' => [-3, 1],
            'string number' => ['7', 7],
            'float' => [3.5, 3],
            'null' => [null, 1],
        ];
    }

    private function idRangeToArray($idRange): array
    {
        if ($idRange === null) {
            return [];
        }

        if (method_exists($idRange, 'toArray')) {
            return $idRange->toArray();
        }

        if (is_array($idRange)) {
            return $idRange;
        }

        return [];
    }
}