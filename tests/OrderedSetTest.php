<?php

declare (strict_types = 1);

namespace Crell\Historia;

use PHPUnit\Framework\TestCase;

class OrderedSetTest extends TestCase
{

    protected function sampleData(): \ArrayObject
    {
        return new \ArrayObject([
            'a' => 'A',
            'b' => 'B',
            'c' => 'C',
            'd' => 'D',
        ]);
    }

    public function testCanReadElements(): void
    {
        $set = new OrderedSet($this->sampleData());

        $this->assertEquals('A', $set['a']);
        $this->assertEquals('C', $set['c']);
    }

    public function testCanCheckForElements(): void
    {
        $set = new OrderedSet($this->sampleData());

        $this->assertTrue(isset($set['a']));
        $this->assertFalse(isset($set['e']));
    }

    public function testCount(): void
    {
        $set = new OrderedSet($this->sampleData());

        $this->assertEquals(4, count($set));
    }

    public function testCannotSetValues(): void
    {
        $set = new OrderedSet($this->sampleData());

        $this->expectException(\LogicException::class);

        $set['e'] = 'E';
    }

    public function testCannotUnsetValues(): void
    {
        $set = new OrderedSet($this->sampleData());

        $this->expectException(\LogicException::class);

        unset($set['a']);
    }

    public function testCanIterate(): void
    {
        $set = new OrderedSet($this->sampleData());

        // This function works by running through all iterations, so if it
        // works then iteration must work.
        $set_array = iterator_to_array($set);

        $this->assertCount(4, $set_array);
    }

    public function testOrderedSet(): void
    {
        $set = new OrderedSet($this->sampleData(), ['b', 'c', 'a', 'd']);

        $set_array = iterator_to_array($set);
        $keys = array_keys($set_array);

        $this->assertEquals(['b', 'c', 'a', 'd'], $keys);
    }

    public function testIncompleteOrderedSet(): void
    {
        $set = new OrderedSet($this->sampleData(), ['b', 'd']);

        $set_array = iterator_to_array($set);
        $keys = array_keys($set_array);

        $this->assertEquals(['b', 'd', 'a', 'c'], $keys);
    }

    public function testOversizedOrderedSet(): void
    {
        $set = new OrderedSet($this->sampleData(), ['b', 'd', 'q']);

        $set_array = iterator_to_array($set);
        $keys = array_keys($set_array);

        $this->assertEquals(['b', 'd', 'a', 'c'], $keys);
    }
}
