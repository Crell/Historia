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
    protected $addRecords = [];

    /**
     * @var array
     */
    protected $deleteRecords = [];

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

    public function getAddRecords()
    {
        return $this->addRecords;
    }

    public function getDeleteRecords() : array
    {
        return $this->deleteRecords;
    }

    /**
     * Returns the number of records in this commit.
     *
     * @return int
     *   The number of records in this commit.
     */
    public function count()
    {
        return count($this->addRecords);
    }

    public function add(Record $record) : self
    {
        $this->addRecords[] = $record;
        return $this;
    }

    public function delete(string $uuid, string $language) : self
    {
        $this->deleteRecords[] = ['uuid' => $uuid, 'language' => $language];
        return $this;
    }
}
