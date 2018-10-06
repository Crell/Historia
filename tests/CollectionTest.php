<?php
declare(strict_types=1);

namespace Crell\Historia;

use PHPUnit\Framework\TestCase;


class CollectionTest extends TestCase
{

    public function setUp()
    {
        parent::setUp();

        $conn = $this->getConnection();
        $conn->query(sprintf('DROP DATABASE IF EXISTS %s', $this->dbName()));
        $conn->query(sprintf('CREATE DATABASE %s', $this->dbName()));

    }

    protected function dbName() : string
    {
        return getenv('HISTORIA_DB_NAME') ?: 'historia';
    }

    protected function getConnection() : \PDO
    {
        $host = getenv('HISTORIA_DB_HOST') ?: '127.0.0.1';
        $port = getenv('HISTORIA_DB_HOST') ?: '3306';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $this->dbName());
        $conn = new \PDO($dsn, 'root', 'test', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            // So we don't have to mess around with cursors and unbuffered queries by default.
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => TRUE,
            // Make sure MySQL returns all matched rows on update queries including
            // rows that actually didn't have to be updated because the values didn't
            // change. This matches common behavior among other database systems.
            \PDO::MYSQL_ATTR_FOUND_ROWS => TRUE,
            // Because MySQL's prepared statements skip the query cache, because it's dumb.
            \PDO::ATTR_EMULATE_PREPARES => TRUE,
            // Make it harder to hack a second query into the query string.
            \PDO::MYSQL_ATTR_MULTI_STATEMENTS => FALSE,
        ]);

        return $conn;
    }

    public function test_can_initialize() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        // This wil throw an exception if the table doesn't exist, as long as PDO is in exception mode.
        $stmt = $this->getConnection()->query('SELECT 1 FROM historia_col_documents');

        // Give us an assertion to keep PHPUnit happy.
        $this->assertInstanceOf(\PDOStatement::class, $stmt);


        /*
        $a = $c->create('documents');

        $a->setValue('Hello World');

        $commit = $c->newCommit();

        $commit->add('documents', $a);

        $c->commit($commit);
        */
    }

    public function test_save_document() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $uuid = '12345';
        $value = 'this is a test';

        $c->save($uuid, $value);

        $saved = $c->load($uuid);
        $this->assertEquals($value, $saved);
    }

    public function test_save_multiple_documents() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();

        $commit->add('12345', 'hello world');
        $commit->add('4567', 'goodbye world');

        $c->commit($commit);

        $r1 = $c->load('12345');
        $r2 = $c->load('4567');
        $this->assertEquals('hello world', $r1);
        $this->assertEquals('goodbye world', $r2);
    }

    public function test_updating_record_works() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();
        $commit->add('12345', 'hello world');
        $c->commit($commit);

        $commit = $c->newCommit();
        $commit->add('12345', 'goodbye world');
        $c->commit($commit);

        $r1 = $c->load('12345');
        $this->assertEquals('goodbye world', $r1);
    }

    public function test_deleting_record() : void
    {
        $this->expectException(RecordNotFound::class);

        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();
        $commit->add('12345', 'hello world');
        $c->commit($commit);

        $commit = $c->newCommit();
        $commit->delete('12345');
        $c->commit($commit);

        // Loading a UUID that doesn't exist should trigger an exception.
        $r1 = $c->load('12345');
    }
}
