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
            start_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW START,
            end_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW END,
            PERIOD FOR SYSTEM_TIME(start_timestamp, end_timestamp),
            PRIMARY KEY (uuid)
        ) WITH SYSTEM VERSIONING");

        $res = $this->conn->query('CREATE FUNCTION CURRENT_XID() RETURNS VARCHAR(18)
            BEGIN
                RETURN (SELECT TRX_ID FROM INFORMATION_SCHEMA.INNODB_TRX
                        WHERE TRX_MYSQL_THREAD_ID = CONNECTION_ID());
            END');

        return $this;
    }

    public function save(string $uuid, string $value)
    {
        $commit = $this->newCommit();
        $commit->add($uuid, $value);
        $this->commit($commit);
    }

    public function load(string $uuid) : string
    {
        $stmt = $this->conn->prepare(sprintf("SELECT document FROM %s WHERE uuid=:uuid", $this->tableName('documents')));
        $stmt->execute([':uuid' => $uuid]);
        $value = $stmt->fetchColumn();
        if (!$value) {
            throw RecordNotFound::forUuid($uuid);
        }
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
        $this->withTransaction(function (\PDO $conn) use ($commit) {
            $addRecords = $commit->getAddRecords();
            if (count($addRecords)) {
                foreach ($addRecords as $uuid => $value) {
                    $stmt = $conn->prepare(sprintf("INSERT INTO %s SET document=:value, uuid=:uuid ON DUPLICATE KEY UPDATE document=:value, updated=NOW()", $this->tableName('documents')));
                    $stmt->execute([
                        ':uuid' => $uuid,
                        ':value' => $value,
                    ]);
                }
            }

            $deleteIds = array_values($commit->getDeleteRecords());
            if (count($deleteIds)) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $query = sprintf('DELETE FROM %s WHERE uuid IN (%s)', $this->tableName('documents'), $placeholders);
                $stmt = $conn->prepare($query);
                $stmt->execute($deleteIds);
            }


        });
    }

    /**
     * Wraps a callable into a transaction.
     *
     * @param callable $func
     *   The callable that makes up the transaction.
     * @return \PDOStatement|null, depending on the query type.
     *
     * @throws \Throwable
     */
    public function withTransaction(callable $func) : ?\PDOStatement
    {
        try {
            $this->conn->beginTransaction();
            $res = $func($this->conn);
            $this->conn->commit();
            return $res;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
}
