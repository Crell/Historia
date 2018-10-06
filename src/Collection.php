<?php
declare(strict_types=1);

namespace Crell\Historia;

use Crell\Historia\Shelf\ShelfInterface;

class Collection
{

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
            language VARCHAR(5),
            updated TIMESTAMP DEFAULT NOW(),
            document TEXT,
            start_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW START,
            end_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW END,
            PERIOD FOR SYSTEM_TIME(start_timestamp, end_timestamp),
            PRIMARY KEY (uuid, language)
        ) WITH SYSTEM VERSIONING");

        $res = $this->conn->query('CREATE FUNCTION CURRENT_XID() RETURNS VARCHAR(18)
            BEGIN
                RETURN (SELECT TRX_ID FROM INFORMATION_SCHEMA.INNODB_TRX
                        WHERE TRX_MYSQL_THREAD_ID = CONNECTION_ID());
            END');

        return $this;
    }

    public function save(Record $record)
    {
        $commit = $this->newCommit();
        $commit->add($record);
        $this->commit($commit);
    }

    public function load(string $uuid) : Record
    {
        $records = $this->loadMultiple([$uuid]);

        if (!count($records)) {
            throw RecordNotFound::forUuid($uuid, $this->language);
        }

        return current(iterator_to_array($records));

    }

    public function loadMultiple(iterable $uuids) : iterable
    {
        // Let people pass in any iterable, but we really do need an array internally.
        if ($uuids instanceof \Traversable) {
            $uuids = iterator_to_array($uuids);
        }

        if (!count($uuids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $values = array_merge([$this->language], $uuids);
        $query = sprintf('SELECT uuid, document, language, updated FROM %s WHERE language=? AND uuid IN (%s)', $this->tableName('documents'), $placeholders);
        $stmt = $this->conn->prepare($query);
        $stmt->execute($values);

        $stmt->setFetchMode(\PDO::FETCH_CLASS, Record::class);

        $returns = function () use ($stmt) {
            foreach ($stmt as $record) {
                yield $record->uuid => $record;
            }
        };

        // Force the records into the order provided.
        return new OrderedSet($returns(), $uuids);
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
                foreach ($addRecords as $record) {
                    $query = sprintf("INSERT INTO %s SET document=:document, language=:language, uuid=:uuid ON DUPLICATE KEY 
                        UPDATE document=:document, language=:language, updated=NOW()", $this->tableName('documents'));
                    $values = [
                        ':uuid' => $record->uuid,
                        ':document' => $record->document,
                        ':language' => $record->language,
                    ];
                    $stmt = $conn->prepare($query);
                    $stmt->execute($values);
                }
            }

            $deleteIds = array_values($commit->getDeleteRecords());
            if (count($deleteIds)) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $values = array_merge([$this->language], $deleteIds);
                $query = sprintf('DELETE FROM %s WHERE language=? AND uuid IN (%s)', $this->tableName('documents'), $placeholders);
                $stmt = $conn->prepare($query);
                $stmt->execute($values);
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
