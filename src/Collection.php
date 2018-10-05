<?php
declare(strict_types=1);

namespace Crell\Historia;


use Crell\Historia\Shelf\ShelfInterface;

class Collection
{

    /** @var ShelfInterface[] */
    protected $shelves;

    /** @var string */
    protected $defaultShelf;

    /**
     * @var string
     */
    protected $language = 'en';

    /**
     * @var string
     */
    protected $workspace = 'default';

    /**
     * @var \PDO
     */
    protected $conn;

    public function __construct(\PDO $conn, string $name, $language = 'en')
    {
        $this->conn = $conn;
        $this->name = $name;
        $this->language = $language;
    }

    protected function tableName(string $table) : string
    {
        return sprintf('historia_%s_%s', $this->name, $table);
    }

    public function initializeSchema() : self
    {

        $res = $this->conn->query("CREATE TABLE IF NOT EXISTS " . $this->tableName('documents') . "(
            uuid VARCHAR(36),
            updated TIMESTAMP DEFAULT NOW(),
            document TEXT,
            PRIMARY KEY (uuid)
        )");

        return $this;
    }

    public function save(string $uuid, string $value)
    {
        try {
            $this->conn->beginTransaction();
            $stmt = $this->conn->prepare(sprintf("INSERT INTO %s SET document=:value, uuid=:uuid ON DUPLICATE KEY UPDATE document=:value, updated=NOW()", $this->tableName('documents')));
            $stmt->execute([
                ':uuid' => $uuid,
                ':value' => $value,
            ]);
        }
        finally {
            $this->conn->commit();
        }
    }

    public function load(string $uuid) : string
    {
        $stmt = $this->conn->prepare(sprintf("SELECT document FROM %s WHERE uuid=:uuid", $this->tableName('documents')));
        $stmt->execute([':uuid' => $uuid]);
        $value = $stmt->fetchColumn();
        return $value;
    }

    public function addShelf(string $name, ShelfInterface $shelf) : self
    {
        $this->shelves[$name] = $shelf;

        if (empty($this->defaultShelf)) {
            $this->setDefaultShelf($name);
        }

        return $this;
    }

    public function setDefaultShelf(string $name) : self
    {
        $this->defaultShelf = $name;
        return $this;
    }

    public function getDefaultShelf() : string
    {
        return $this->defaultShelf;
    }

    public function name() : string
    {
        return $this->name;
    }

    public function forLanguage(string $language) : self
    {
        $new = clone $this;
        $new->language = $language;

        return $new;
    }

    public function forWorkspace(string $workspace) : self
    {
        $new = clone $this;
        $new->workspace = $workspace;

        return $new;
    }

    public function create(string $shelf = null)
    {
        $shelf = $shelf ?? $this->defaultShelf;
        return $this->shelves[$shelf]->create();
    }

    public function newCommit(string $author = null, string $message = null) : Commit
    {
        return new Commit($author, $message);
    }

    public function commit(Commit $commit)
    {
        $records = $commit->getRecords();
        // @todo Hard code text for now. We'll sort this out later.

        $records = $records[$this->defaultShelf];

        // Start DB transaction



        // Close DB transaction
    }
}
