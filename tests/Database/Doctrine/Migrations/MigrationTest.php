<?php

declare(strict_types=1);

namespace Yousign\SafeMigrations\Tests\Database\Doctrine\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Query\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Yousign\SafeMigrations\Doctrine\Migration;

final class MigrationTest extends TestCase
{
    private Connection&MockObject $connection;
    private TestMigration $migration;

    /**
     * @before
     */
    protected function before(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->migration = new TestMigration($this->connection, new NullLogger());
    }

    /**
     * @after
     */
    protected function after(): void
    {
        unset($this->connection, $this->migration);
    }

    public function testCannotUseAddSqlMethod(): void
    {
        $migration = new class($this->connection, new NullLogger()) extends TestMigration {
            public function up(Schema $schema): void
            {
                $this->addSql('OUPS');
            }
        };

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('Using method ::addSql() directly is forbidden');

        $migration->up(new Schema());
    }

    public function testAddUnsafeSqlWithStatementTimeout(): void
    {
        $this->migration->addUnsafeSql('SELECT 1', statementTimeout: 5);

        $this->assertSql([
            "SET statement_timeout TO '5s'",
            'SELECT 1',
            "SET statement_timeout TO '0'",
        ]);
    }

    public function testAddIndexConcurrentlyWithOneColumn(): void
    {
        $this->migration->addIndex('idx_approver_email', 'approver', 'email');

        $this->assertSql([
            'SET statement_timeout TO \'0\'',
            'SET lock_timeout TO \'0\'',
            'CREATE INDEX CONCURRENTLY idx_approver_email ON approver (email)',
            'SET lock_timeout TO \'3s\'',
            'ANALYZE approver',
        ]);
    }

    public function testAddIndexConcurrentlyWithMultipleColumns(): void
    {
        $this->migration->addIndex('idx_approver_first_name_email', 'approver', ['first_name', 'email']);

        $this->assertSql([
            'SET statement_timeout TO \'0\'',
            'SET lock_timeout TO \'0\'',
            'CREATE INDEX CONCURRENTLY idx_approver_first_name_email ON approver (first_name,email)',
            'SET lock_timeout TO \'3s\'',
            'ANALYZE approver',
        ]);
    }

    public function testAddUniqueIndexConcurrently(): void
    {
        $this->migration->addIndex('uq_signer_email', 'signer', 'email', unique: true);

        $this->assertSql([
            'SET statement_timeout TO \'0\'',
            'SET lock_timeout TO \'0\'',
            'CREATE UNIQUE INDEX CONCURRENTLY uq_signer_email ON signer (email)',
            'SET lock_timeout TO \'3s\'',
            'ANALYZE signer',
        ]);
    }

    public function testAddIndexConcurrentlyUsingGinMethod(): void
    {
        $this->migration->addIndex('idx_signer_email', 'signer', 'email', usingMethod: 'GIN');

        $this->assertSql([
            'SET statement_timeout TO \'0\'',
            'SET lock_timeout TO \'0\'',
            'CREATE INDEX CONCURRENTLY idx_signer_email ON signer USING GIN(email)',
            'SET lock_timeout TO \'3s\'',
            'ANALYZE signer',
        ]);
    }

    public function testAddIndexConcurrentlyWhere(): void
    {
        $this->migration->addIndex('idx_signer_email', 'signer', 'email', where: 'name = "foo"');

        $this->assertSql([
            'SET statement_timeout TO \'0\'',
            'SET lock_timeout TO \'0\'',
            'CREATE INDEX CONCURRENTLY idx_signer_email ON signer (email) WHERE name = "foo"',
            'SET lock_timeout TO \'3s\'',
            'ANALYZE signer',
        ]);
    }

    public function testRenameIndex(): void
    {
        $this->migration->renameIndex('idx_email_signer', 'idx_signer_email');

        $this->assertSql('ALTER INDEX idx_email_signer RENAME TO idx_signer_email');
    }

    public function testDropIndex(): void
    {
        $this->migration->dropIndex('idx_signer_email');

        $this->assertSql('DROP INDEX CONCURRENTLY IF EXISTS idx_signer_email');
    }

    public function testThrownExceptionWhenAddingIndexWithTooLongIndexName(): void
    {
        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('Index name is too long');
        $this->migration->addIndex(str_repeat('a', 64), 'signer', ['email']);
    }

    public function testAddColumnWithDefaultArguments(): void
    {
        $this->migration->addColumn('signer', 'email', 'TEXT');

        $this->assertSql([
            "SET statement_timeout TO '5s'",
            'ALTER TABLE signer ADD COLUMN email TEXT DEFAULT NULL',
            "SET statement_timeout TO '0'",
        ]);
    }

    public function testAddColumnWithADefaultValue(): void
    {
        $this->migration->addColumn('signer', 'email', 'TEXT', "'hello'");

        $this->assertSql([
            "SET statement_timeout TO '5s'",
            'ALTER TABLE signer ADD COLUMN email TEXT DEFAULT \'hello\'',
            "SET statement_timeout TO '0'",
        ]);
    }

    public function testAddColumnWithADefaultValueAndNotNull(): void
    {
        $this->migration->addColumn('signer', 'email', 'TEXT', "'hello'", nullable: false);

        $this->assertSql([
            "SET statement_timeout TO '5s'",
            'ALTER TABLE signer ADD COLUMN email TEXT DEFAULT \'hello\' NOT NULL',
            "SET statement_timeout TO '0'",
        ]);
    }

    public function testAddColumnWithStringNullAsDefaultValueThrowException(): void
    {
        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('requires the usage of null PHP value instead of string one as default value');
        $this->migration->addColumn('signer', 'email', 'TEXT', 'NULL');
    }

    public function testDropDefaultOnColumn(): void
    {
        $this->migration->dropDefaultOnColumn('signer', 'email');

        $this->assertSql('ALTER TABLE signer ALTER email DROP DEFAULT');
    }

    public function testSetDefaultValueOnColumn(): void
    {
        $this->migration->setDefaultOnColumn('signer', 'email', "'foo@bar.x'");

        $this->assertSql([
            "SET statement_timeout TO '5s'",
            "ALTER TABLE signer ALTER email SET DEFAULT 'foo@bar.x'",
            "SET statement_timeout TO '0'",
        ]);
    }

    public function testSetColumnNullable(): void
    {
        $this->migration->setColumnNullable('signer', 'email');

        $this->assertSql('ALTER TABLE signer ALTER email DROP NOT NULL');
    }

    public function testSetColumnNotNullable(): void
    {
        $this->migration->setColumnNotNullable('signer', 'email');

        $this->assertSql([
            'ALTER TABLE signer DROP CONSTRAINT IF EXISTS "chk_null_signer_email"',
            'ALTER TABLE signer ADD CONSTRAINT "chk_null_signer_email" CHECK (email IS NOT NULL) NOT VALID',
            "SET statement_timeout TO '0'",
            'ALTER TABLE signer VALIDATE CONSTRAINT "chk_null_signer_email"',
            "SET statement_timeout TO '5s'",
            'ALTER TABLE signer ALTER email SET NOT NULL',
            'ALTER TABLE signer DROP CONSTRAINT "chk_null_signer_email"',
            "SET statement_timeout TO '0'",
        ]);
    }

    public function testRemoveCommentOnColumn(): void
    {
        $this->migration->commentOnColumn('signer', 'email', null);

        $this->assertSql('COMMENT ON COLUMN signer.email IS NULL');
    }

    public function testCommentDC2TypeOnColumn(): void
    {
        $this->migration->commentOnColumn('signer', 'email', '(DC2Type:webhook_jobs_log_id)');

        $this->assertSql("COMMENT ON COLUMN signer.email IS '(DC2Type:webhook_jobs_log_id)'");
    }

    public function testRenameConstraint(): void
    {
        $this->migration->renameConstraint('signer', 'id_pkey', 'pkey_id');

        $this->assertSql('ALTER TABLE signer RENAME CONSTRAINT id_pkey TO pkey_id');
    }

    public function testDropColumn(): void
    {
        $this->migration->dropColumn('signer', 'email');

        $this->assertSql('ALTER TABLE signer DROP email');
    }

    public function testCreateTable(): void
    {
        $this->migration->createTable('signer', [
            'id UUID NOT NULL',
            'user_id UUID NOT NULL',
            'created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL',
            'PRIMARY KEY(id)',
        ]);

        $this->assertSql(<<<'SQL'
            CREATE TABLE signer (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
            )
            SQL
        );
    }

    public function testCreateTableDoesNotSupportForeignKeys(): void
    {
        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('It\'s not possible to add a foreign key');
        $this->migration->createTable('signer', [
            'id UUID NOT NULL',
            'user_id UUID NOT NULL',
            'created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL',
            'PRIMARY KEY(id)',
            'CONSTRAINT fk_customer FOREIGN KEY(customer_id) REFERENCES customers(customer_id)',
        ]);
    }

    public function testAddForeignKeyWithDefaultValue(): void
    {
        $this->migration->addForeignKey('signer', 'fk_signer_address', 'address', 'address', 'id');

        $this->assertSql([
            'ALTER TABLE signer ADD CONSTRAINT fk_signer_address FOREIGN KEY (address) REFERENCES address(id) NOT VALID',
            'ALTER TABLE signer VALIDATE CONSTRAINT fk_signer_address',
        ]);
    }

    public function testAddForeignKeyWithOnDeleteAction(): void
    {
        $this->migration->addForeignKey('signer', 'fk_signer_address', 'address', 'address', 'id', 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->assertSql([
            'ALTER TABLE signer ADD CONSTRAINT fk_signer_address FOREIGN KEY (address) REFERENCES address(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE NOT VALID',
            'ALTER TABLE signer VALIDATE CONSTRAINT fk_signer_address',
        ]);
    }

    /**
     * @param string|string[] $expectedSql
     */
    public function assertSql(string|array $expectedSql): void
    {
        $expectedSql = \is_string($expectedSql) ? [$expectedSql] : $expectedSql;
        $expectedSql = ["SET lock_timeout TO '3s'", ...$expectedSql];
        self::assertSame($expectedSql, array_map(static fn (Query $query) => $query->getStatement(), $this->migration->getSql()));
    }
}

/**
 * Set protected methods to "public" to ease the test of the Abstract class under test.
 */
class TestMigration extends Migration
{
    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        parent::__construct($connection, $logger);
    }

    public function up(Schema $schema): void
    {
        throw new \Exception('Unused for this test');
    }

    public function addUnsafeSql(string $sql, array $params = [], array $types = [], ?int $statementTimeout = null): void
    {
        parent::addUnsafeSql($sql, $params, $types, $statementTimeout);
    }

    public function addIndex(string $name, string $table, array|string $columns, bool $unique = false, string $usingMethod = '', string $where = ''): void
    {
        parent::addIndex($name, $table, $columns, $unique, $usingMethod, $where);
    }

    public function dropIndex(string $name): void
    {
        parent::dropIndex($name);
    }

    public function renameIndex(string $from, string $to): void
    {
        parent::renameIndex($from, $to);
    }

    public function addColumn(string $table, string $name, string $type, ?string $defaultValue = null, bool $nullable = true): void
    {
        parent::addColumn($table, $name, $type, $defaultValue, $nullable);
    }

    public function dropDefaultOnColumn(string $table, string $name): void
    {
        parent::dropDefaultOnColumn($table, $name);
    }

    public function setDefaultOnColumn(string $table, string $name, string $value): void
    {
        parent::setDefaultOnColumn($table, $name, $value);
    }

    public function setColumnNullable(string $table, string $name): void
    {
        parent::setColumnNullable($table, $name);
    }

    public function setColumnNotNullable(string $table, string $name): void
    {
        parent::setColumnNotNullable($table, $name);
    }

    public function commentOnColumn(string $table, string $name, ?string $comment): void
    {
        parent::commentOnColumn($table, $name, $comment);
    }

    public function renameConstraint(string $table, string $from, string $to): void
    {
        parent::renameConstraint($table, $from, $to);
    }

    public function dropColumn(string $table, string $name): void
    {
        parent::dropColumn($table, $name);
    }

    public function createTable(string $table, array $columnDefinitions): void
    {
        parent::createTable($table, $columnDefinitions);
    }

    public function addForeignKey(string $table, string $name, string $column, string $referenceTable, string $referenceColumn, ?string $options = null): void
    {
        parent::addForeignKey($table, $name, $column, $referenceTable, $referenceColumn, $options);
    }
}
