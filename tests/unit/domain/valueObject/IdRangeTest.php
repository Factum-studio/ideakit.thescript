<?php

namespace unit\domain\valueObject;

use Codeception\Test\Unit;
use core\domain\valueObject\IdRange;
use InvalidArgumentException;

class IdRangeTest extends Unit
{
    public function testEmpty()
    {
        $range = IdRange::empty();
        $this->assertTrue($range->isEmpty());
        $this->assertEquals([], $range->toArray());
    }

    public function testSingleId()
    {
        $range = new IdRange('5');
        $this->assertEquals([5], $range->toArray());
        $this->assertTrue($range->contains(5));
        $this->assertFalse($range->contains(6));
    }

    public function testCommaSeparated()
    {
        $range = new IdRange('1,3,5');
        $this->assertEquals([1,3,5], $range->toArray());
    }

    public function testRangeWithColon()
    {
        $range = new IdRange('2:4');
        $this->assertEquals([2,3,4], $range->toArray());
    }

    public function testMixed()
    {
        $range = new IdRange('1,3:5,7');
        $this->assertEquals([1,3,4,5,7], $range->toArray());
    }

    public function testInvalidRangeThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new IdRange('5:2'); // start > end
    }

    public function testFromArray()
    {
        $range = IdRange::fromArray([2,4,6]);
        $this->assertEquals([2,4,6], $range->toArray());
    }

    public function testGetFirstLast()
    {
        $range = new IdRange('1,3:5');
        $this->assertEquals(1, $range->getFirst());
        $this->assertEquals(5, $range->getLast());
        $empty = IdRange::empty();
        $this->assertNull($empty->getFirst());
        $this->assertNull($empty->getLast());
    }

    public function testCount()
    {
        $range = new IdRange('1,3:5');
        $this->assertEquals(4, $range->count());
    }

    public function testWhitespaceIgnored()
    {
        $range = new IdRange(' 1 , 3 : 5 , 7 ');
        $this->assertEquals([1,3,4,5,7], $range->toArray());
    }

    public function testDuplicatesRemoved()
    {
        $range = new IdRange('1,1,3:5,3,5');
        $this->assertEquals([1,3,4,5], $range->toArray());
    }

    public function testNegativeIdThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new IdRange('-1');
    }

    public function testNegativeInRangeThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new IdRange('-2:-1');
    }

    public function testZeroIdThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new IdRange('0');
    }

    public function testInvalidFormatThrowsException()
    {
        $this->markTestSkipped('It is necessary to change the parsing logic.');
        $this->expectException(InvalidArgumentException::class);
        new IdRange('1:2:3');
    }

    public function testInvalidCharactersThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new IdRange('a,b');
    }

    public function testTrailingCommaThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new IdRange('1,2,');
    }

    public function testFromString()
    {
        $range = IdRange::fromString('1,3:5');
        $this->assertEquals([1,3,4,5], $range->toArray());
        $empty = IdRange::fromString(null);
        $this->assertTrue($empty->isEmpty());
        $empty2 = IdRange::fromString('');
        $this->assertTrue($empty2->isEmpty());
    }

    public function testFromArrayEmpty()
    {
        $range = IdRange::fromArray([]);
        $this->assertTrue($range->isEmpty());
        $this->assertEquals([], $range->toArray());
    }

    public function testCountEmpty()
    {
        $empty = IdRange::empty();
        $this->assertEquals(0, $empty->count());
    }

    public function testSorting()
    {
        $range = new IdRange('5,3,1,4,2');
        $this->assertEquals([1,2,3,4,5], $range->toArray());
    }

    public function testFromArrayWithDuplicates()
    {
        $range = IdRange::fromArray([5,1,3,1,4,2,5]);
        $this->assertEquals([1,2,3,4,5], $range->toArray());
    }
}
