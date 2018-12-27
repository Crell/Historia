<?php
declare(strict_types=1);

namespace Crell\Historia;

class Collection
{

    public const DEFAULT_WORKSPACE = 'default';

    /**
     * @var string
     */
    protected $language = 'en';

    /**
     * @var string
     */
    protected $workspace = self::DEFAULT_WORKSPACE;


    /**
     * @var int
     *
     * Flag for a normal record.
     */
    protected const FLAG_NORMAL = 0;

    /**
     * @var int
     *
     * Flag for a record that is a stub. That is, it contains no data and exists only so that we can join against it.
     */
    protected const FLAG_STUB = 1;

    /**
     * @var int
     *
     * Flag for a deleted record. This exists primarily so that we can put a deleted marker in a workspace
     * that will let us join against the base table to detect that a record has been deleted in the workspace.
     */
    protected const FLAG_DELETED = 2;

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
            workspace VARCHAR(64),
            updated TIMESTAMP DEFAULT NOW(),
            flag TINYINT(1) DEFAULT 0,
            document TEXT,
            start_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW START,
            end_timestamp TIMESTAMP(6) GENERATED ALWAYS AS ROW END,
            PERIOD FOR SYSTEM_TIME(start_timestamp, end_timestamp),
            PRIMARY KEY (uuid, language, workspace),
            INDEX (flag)
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

        $tableName = $this->tableName('documents');

        // If reading from the default workspace, we can use a much simpler query. This also lets us avoid dealing with
        // stub records, as we can explicitly avoid those.
        if ($this->workspace == static::DEFAULT_WORKSPACE) {
            $placeholders = implode(',', array_fill(0, count($uuids), '?'));
            $values = array_merge([$this->workspace, $this->language, static::FLAG_NORMAL], $uuids);
            $query = sprintf('
            WITH base AS (SELECT * FROM %s WHERE workspace=? AND language=?)
            SELECT uuid, document, language, updated
            FROM base
            WHERE flag =? AND base.uuid IN (%s)
        ', $tableName, $placeholders);
            $stmt = $this->conn->prepare($query);
            $stmt->execute($values);
        }
        else {
            $placeholders = implode(',', array_fill(0, count($uuids), '?'));
            $values = array_merge([$this->workspace, $this->language, static::DEFAULT_WORKSPACE, $this->language, static::FLAG_NORMAL], $uuids);
            $query = sprintf('
            WITH branch AS (SELECT * FROM %s WHERE workspace=? AND language=?),
                 base   AS (SELECT * FROM %s WHERE workspace=? AND language=?)
            SELECT
                COALESCE(branch.uuid, base.uuid) AS uuid,
                COALESCE(branch.document, base.document) AS document,
                COALESCE(branch.language, base.language) AS language,
                COALESCE(branch.updated, base.updated) AS updated
            FROM base LEFT JOIN branch
                ON branch.uuid=base.uuid
                AND branch.language=base.language
            WHERE (branch.flag=? OR branch.flag IS NULL) AND base.uuid IN (%s)
        ', $tableName, $tableName, $placeholders);
            $stmt = $this->conn->prepare($query);
            $stmt->execute($values);
        }

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

            $addRecords = $commit->getAddRecords();
            if (count($addRecords)) {
                foreach ($addRecords as $record) {
                    $this->processAddRecord($conn, $record);
                    $affected['added'][] = ['uuid' => $record->uuid, 'language' => $record->language];
                }
            }

            $deleteRecords = array_values($commit->getDeleteRecords());
            if (count($deleteRecords)) {
                foreach ($deleteRecords as $record) {
                    $this->processDeleteRecord($conn, $record);
                    $affected['deleted'][] = ['uuid' => $record['uuid'], 'language' => $record['language']];
                }
            }

            if (count($affected)) {
                $this->processRecordTransaction($conn, $affected);
            }
        });
    }

    function processDeleteRecord(\PDO $conn, array $record) : void
    {
        // @todo For now we're never deleting, just flagging something as deleted. That lets the workspace join work
        // so that we can "delete" items in a workspace.  It MAY make sense to do a for-reals delete in the default
        // workspace.  That's something to figure out later.

        $tableName = $this->tableName('documents');

        // We use an ODKU so that we can at least set an empty delete record in a workspace if the workspace has no
        // version of the document yet.
        $query = sprintf("INSERT INTO %s SET language=:language, uuid=:uuid, workspace=:workspace, flag=:flag ON DUPLICATE KEY
            UPDATE flag=:flag, updated=NOW()", $tableName);
        $values = [
            ':uuid' => $record['uuid'],
            ':language' => $record['language'],
            ':workspace' => $this->workspace,
            ':flag' => static::FLAG_DELETED,
        ];
        $stmt = $conn->prepare($query);
        $stmt->execute($values);
    }

    function processRecordTransaction(\PDO $conn, array $affected) : void
    {
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

    protected function processAddRecord(\PDO $conn, Record $record) : void
    {
        $tableName = $this->tableName('documents');

        // Doing it the ugly multi-query way may not be necessary; it was only done to try and figure out
        // why the transaction ID is only sometimes readable.  The ODKU is probably workable.
        // Of course, in a just world we'd use an ANSI MERGE query, but the world is not just.

        /*
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
        */

        $tableName = $this->tableName('documents');

        // Life is simply easier if the base workspace always has a record, even if it was created in a branch.
        // Therefore, create a stub in the base workspace if one does not exist already.
        // We create it first so that if there is any difference in the timestamp, the stub comes before the branch document.
        if ($this->workspace != static::DEFAULT_WORKSPACE && !$this->recordExistsInBaseWorkspace($conn, $record)) {
            $query = sprintf("INSERT INTO %s SET document='', language=:language, uuid=:uuid, workspace=:workspace, flag=:flag", $tableName);
            $values = [
                ':uuid' => $record->uuid,
                ':language' => $record->language,
                ':workspace' => static::DEFAULT_WORKSPACE,
                ':flag' => static::FLAG_STUB,
            ];
            $stmt = $conn->prepare($query);
            $stmt->execute($values);
        }


        $query = sprintf("INSERT INTO %s SET document=:document, language=:language, uuid=:uuid, workspace=:workspace ON DUPLICATE KEY
            UPDATE document=:document, language=:language, updated=NOW()", $tableName);
        $values = [
            ':uuid' => $record->uuid,
            ':document' => $record->document,
            ':language' => $record->language,
            ':workspace' => $this->workspace,
        ];
        $stmt = $conn->prepare($query);
        $stmt->execute($values);


    }

    protected function recordExistsInBaseWorkspace(\PDO $conn, Record $record) : bool
    {
        $tableName = $this->tableName('documents');

        $query = sprintf("SELECT COUNT(uuid) AS num FROM %s WHERE uuid=:uuid AND language=:language AND workspace=:workspace", $tableName);
        $values = [
            ':uuid' => $record->uuid,
            ':language' => $record->language,
            ':workspace' => static::DEFAULT_WORKSPACE,
        ];
        $stmt = $conn->prepare($query);
        $stmt->execute($values);
        $count = (int)$stmt->fetchColumn();
        return (bool)$count;
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
