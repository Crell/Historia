<?php
declare(strict_types=1);

namespace Crell\Historia;

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
        $this->conn->query("CREATE TABLE IF NOT EXISTS " . $this->tableName('documents') . "(
            uuid VARCHAR(36),
            language VARCHAR(5),
            updated TIMESTAMP DEFAULT NOW(),
            document TEXT,
            start_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW START,
            end_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW END,
            PERIOD FOR SYSTEM_TIME(start_timestamp, end_timestamp),
            PRIMARY KEY (uuid, language)
        ) WITH SYSTEM VERSIONING");

        $this->conn->query('CREATE FUNCTION CURRENT_XID() RETURNS VARCHAR(18)
            BEGIN
                RETURN (SELECT TRX_ID FROM INFORMATION_SCHEMA.INNODB_TRX
                        WHERE TRX_MYSQL_THREAD_ID = CONNECTION_ID());
            END');

        $this->conn->query("CREATE TABLE IF NOT EXISTS " . $this->tableName('transactions') . "(
            transaction VARCHAR(36),
            recorded_at TIMESTAMP DEFAULT NOW(),
            affected TEXT,
            CHECK (JSON_VALID(affected)),
            PRIMARY KEY (transaction)
        )");

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
            $affected = [];

            $tableName = $this->tableName('documents');

            $addRecords = $commit->getAddRecords();
            if (count($addRecords)) {
                foreach ($addRecords as $record) {
                    // Doing it the ugly multi-query way may not be necessary; it was only done to try and figure out
                    // why the transaction ID is only sometimes readable.  The ODKU is probably workable.
                    // Of course, in a just world we'd use an ANSI MERGE query, but the world is not just.

                    $query = sprintf('SELECT 1 FROM %s WHERE uuid=:uuid AND language=:language', $tableName);
                    $values = [
                        ':uuid' => $record->uuid,
                        ':language' => $record->language,
                    ];
                    $stmt = $conn->prepare($query);
                    $stmt->execute($values);

                    if (!$stmt->fetchColumn()) {
                        $stmt = $conn->prepare(sprintf('INSERT INTO %s (uuid, language, document) VALUES (:uuid, :language, :document)', $tableName));
                        $stmt->execute([
                            ':uuid' => $record->uuid,
                            ':language' => $record->language,
                            ':document' => $record->document,
                        ]);
                    }
                    else {
                        $stmt = $conn->prepare(sprintf('UPDATE %s SET document=:document WHERE uuid=:uuid AND language=:language', $tableName));
                        $stmt->execute([
                            ':uuid' => $record->uuid,
                            ':language' => $record->language,
                            ':document' => $record->document,
                        ]);
                    }

                    /*
                    $query = sprintf("INSERT INTO %s SET document=:document, language=:language, uuid=:uuid ON DUPLICATE KEY
                        UPDATE document=:document, language=:language, updated=NOW()", $this->tableName('documents'));
                    $values = [
                        ':uuid' => $record->uuid,
                        ':document' => $record->document,
                        ':language' => $record->language,
                    ];
                    $stmt = $conn->prepare($query);
                    $stmt->execute($values);
                    */

                    $affected['added'][] = ['uuid' => $record->uuid, 'language' => $record->language];
                }
            }

            $deleteIds = array_values($commit->getDeleteRecords());
            if (count($deleteIds)) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $values = array_merge([$this->language], $deleteIds);
                $query = sprintf('DELETE FROM %s WHERE language=? AND uuid IN (%s)', $this->tableName('documents'), $placeholders);
                $stmt = $conn->prepare($query);
                $stmt->execute($values);
                // @todo This is the wrong language, but we'll fix it for this whole block later.
                foreach ($deleteIds as $uuid) {
                    $affected['deleted'][] = ['uuid' => $uuid, 'language' => $this->language];
                }
            }

            if (count($affected)) {
                // This whole section is horribly buggy. The transaction is always present i nthe table when selecting the whole thing.
                // However, reading just the current transaction's ID fails deterministically but unpredictably. I have NFI why
                // it fails sometimes and not others, but once a test starts failing it always fails.
                // It seems to be an issue with the trx_mysql_thread_id only sometimes matching the connection ID. This needs
                // further research.

                //$query = sprintf('INSERT INTO %s SET transaction=CURRENT_XID(), affected=:affected', $this->tableName('transactions'));
                //$stmt = $conn->prepare($query);
                //$stmt->execute([':affected' => json_encode($affected)]);

                /*
                This is all debugging code.

                $trxs = $conn->query("SELECT * FROM INFORMATION_SCHEMA.INNODB_TRX")->fetchAll(\PDO::FETCH_ASSOC);
                print_r($trxs);

                $connid = $conn->query("SELECT CONNECTION_ID() AS conn_id")->fetchObject();
                if ($connid) {
                    print_r($connid);
                } else {
                    print_r($connid);
                }

                $xid = $conn->query("SELECT CURRENT_XID() AS xid")->fetchObject();
                if ($xid) {
                    print_r($xid);
                } else {
                    print_r($xid);
                }
                */
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
