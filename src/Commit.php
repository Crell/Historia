<?php
declare(strict_types=1);

namespace Crell\Historia;

/**
 * This class is a command object that represents a commit to be made.
 */
class Commit implements \Countable
{
    /**
     * The commit message for this commit.
     *
     * @var string
     */
    protected $message = 'No message';

    /**
     * The author of this commit.
     *
     * This is a domain-specific value; it could be a username, email address, or the UUID of another
     * record.  It's up to the implementing library to give it meaning.
     *
     * @var string
     */
    protected $author = 'Anonymous';

    /**
     * @var array
     */
    protected $records = [];

    /**
     * Constructs a new Commit object.
     *
     * @param string $author
     *   The author of this commit.
     * @param string $message
     *   The commit message for this commit.
     */
    public function __construct(string $author = null, string $message = null)
    {
        $this->author = $author ?? $this->author;
        $this->message = $message ?? $this->message;
    }

    /**
     * Returns an iterator over the current set of records in this commit.
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->records);
    }

    public function getRecords()
    {
        return $this->records;
    }

    /**
     * Returns the number of records in this commit.
     *
     * @return int
     *   The number of records in this commit.
     */
    public function count()
    {
        return count($this->records);
    }

    public function add(string $shelf, $record) : self
    {
        $this->records[$shelf][] = $record;
        return $this;
    }
}
