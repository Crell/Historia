<?php

declare (strict_types = 1);

namespace Crell\Historia;

use PHPUnit\Framework\TestCase;

class OrderedSetTest extends TestCase
{

    protected function sampleData()
    {
        return new \ArrayObject([
            'a' => 'A',
            'b' => 'B',
            'c' => 'C',
            'd' => 'D',
        ]);
    }

    public function testCanReadElements()
    {
        $set = new OrderedSet($this->sampleData());

        $this->assertEquals('A', $set['a']);
        $this->assertEquals('C', $set['c']);
    }

    public function testCanCheckForElements()
    {
        $set = new OrderedSet($this->sampleData());

        $this->assertTrue(isset($set['a']));
        $this->assertFalse(isset($set['e']));
    }

    public function testCount()
    {
        $set = new OrderedSet($this->sampleData());

        $this->assertEquals(4, count($set));
    }

    public function testCannotSetValues()
    {
        $set = new OrderedSet($this->sampleData());

        $this->expectException(\LogicException::class);

        $set['e'] = 'E';
    }

    public function testCannotUnsetValues()
    {
        $set = new OrderedSet($this->sampleData());

        $this->expectException(\LogicException::class);

        unset($set['a']);
    }

    public function testCanIterate()
    {
        $set = new OrderedSet($this->sampleData());

        // This function works by running through all iterations, so if it
        // works then iteration must work.
        $set_array = iterator_to_array($set);

        $this->assertCount(4, $set_array);
    }

    public function testOrderedSet()
    {
        $set = new OrderedSet($this->sampleData(), ['b', 'c', 'a', 'd']);

        $set_array = iterator_to_array($set);
        $keys = array_keys($set_array);

        $this->assertEquals(['b', 'c', 'a', 'd'], $keys);
    }

    public function testIncompleteOrderedSet()
    {
        $set = new OrderedSet($this->sampleData(), ['b', 'd']);

        $set_array = iterator_to_array($set);
        $keys = array_keys($set_array);

        $this->assertEquals(['b', 'd', 'a', 'c'], $keys);
    }

    public function testOversizedOrderedSet()
    {
        $set = new OrderedSet($this->sampleData(), ['b', 'd', 'q']);

        $set_array = iterator_to_array($set);
        $keys = array_keys($set_array);

        $this->assertEquals(['b', 'd', 'a', 'c'], $keys);
    }
}
