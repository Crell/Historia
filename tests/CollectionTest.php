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
    }

    public function test_save_document() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $uuid = '12345';
        $value = 'this is a test';

        $c->save(new Record($uuid, 'en', $value));

        $saved = $c->load($uuid);
        $this->assertEquals($value, $saved->document);
    }

    public function test_save_multiple_documents() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();

        $commit->add(new Record('12345', 'en', 'hello world'))
            ->add(new Record('4567', 'en', 'goodbye world'));

        $c->commit($commit);

        $r1 = $c->load('12345');
        $r2 = $c->load('4567');
        $this->assertEquals('hello world', $r1->document);
        $this->assertEquals('goodbye world', $r2->document);
    }

    public function test_updating_record_works() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();
        $commit->add(new Record('12345', 'en', 'hello world'));
        $c->commit($commit);

        $commit = $c->newCommit();
        $commit->add(new Record('12345', 'en', 'goodbye world'));
        $c->commit($commit);

        $r1 = $c->load('12345');
        $this->assertEquals('goodbye world', $r1->document);
    }

    public function test_deleting_record() : void
    {
        $this->expectException(RecordNotFound::class);

        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();
        $commit->add(new Record('12345', 'en', 'hello world'));
        $c->commit($commit);

        $commit = $c->newCommit();
        $commit->delete('12345', 'en');
        $c->commit($commit);

        // Loading a UUID that doesn't exist should trigger an exception.
        $r1 = $c->load('12345');
    }

    public function test_load_multiple_existing_records() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();

        $commit->add(new Record('12345', 'en', 'hello world'))
            ->add(new Record('4567', 'en', 'goodbye world'));

        $c->commit($commit);

        $records = $c->loadMultiple(['12345', '4567']);

        $this->assertCount(2, $records);
        $this->assertEquals('hello world', $records['12345']->document);
        $this->assertEquals('goodbye world', $records['4567']->document);
    }

    public function test_load_multiple_partially_existing_records() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();

        $commit->add(new Record('12345', 'en', 'hello world'));

        $c->commit($commit);

        $records = $c->loadMultiple(['12345', '4567']);

        $this->assertCount(1, $records);
        $this->assertEquals('hello world', $records['12345']->document);
        $this->assertEquals('12345', $records['12345']->uuid);
        $this->assertInstanceOf(\DateTimeImmutable::class, $records['12345']->updated);
    }

    public function test_load_multiple_on_no_records_is_empty_return() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $records = $c->loadMultiple(['12345', '4567']);

        $this->assertCount(0, $records);
    }

    public function test_saving_in_different_languages() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();
        $commit->add(new Record('12345', 'en', 'hello world'));
        $c->commit($commit);

        $cFr = $c->forLanguage('fr');

        $commit = $cFr->newCommit();
        $commit->add(new Record('12345', 'fr', 'bonjour monde'));
        $cFr->commit($commit);

        $english = $c->load('12345');
        $french = $c->forLanguage('fr')->load('12345');

        $this->assertEquals('hello world', $english->document);
        $this->assertEquals('bonjour monde', $french->document);
    }

    public function test_deleting_selected_languages() : void
    {
        $c = new Collection($this->getConnection(), 'col');
        $c->initializeSchema();

        $commit = $c->newCommit();
        $commit->add(new Record('12345', 'en', 'hello world'))
            ->add(new Record('12345', 'fr', 'bonjour monde'));
        $c->commit($commit);

        $commit = $c->newCommit();
        $commit->delete('12345', 'en');
        $c->commit($commit);

        // French should still be there, so this should not error.
        $c->forLanguage('fr')->load('12345');

        $this->expectException(RecordNotFound::class);
        // This tries to load in English, which we just deleted.
        $c->load('12345');
    }
}
