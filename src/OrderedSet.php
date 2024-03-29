<?php

declare (strict_types = 1);

namespace Crell\Historia;

/**
 * Takes a keyed iterator and forces it to return values in a specified order.
 *
 * Items will be returned in the order specified by key. Any unmentioned
 * items will be returned in their original order.
 */
class OrderedSet implements \ArrayAccess, \Countable, \IteratorAggregate
{
    protected array $values = [];

    public function __construct(\Traversable $iterator, array $order = [])
    {
        $values = iterator_to_array($iterator);

        if ($order) {
            foreach ($order as $key) {
                if (array_key_exists($key, $values)) {
                    $this->values[$key] = $values[$key];
                    unset($values[$key]);
                }
            }
            // Any unordered items go at the end, in whatever order.
            if ($values) {
                $this->values += $values;
            }
        }
        else {
            $this->values = $values;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayObject($this->values);
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return count($this->values);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset): bool
    {
        return isset($this->values[$offset]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($offset): mixed
    {
        return $this->values[$offset];
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('Cannot set documents in a read-only document set.');
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset): void
    {
        throw new \LogicException('Cannot unset documents in a read-only document set.');
    }


}
