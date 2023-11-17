<?php

declare(strict_types=1);

namespace Yousign\SafeMigrations\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use Psr\Log\LoggerInterface;

use function Symfony\Component\String\u;

abstract class Migration extends AbstractMigration
{
    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        parent::__construct($connection, $logger);

        // Don't use `addUnsafeSql()` as the container is not initiated in the constructor
        parent::addSql("SET lock_timeout TO '3s'");
    }

    public function isTransactional(): bool
    {
        return false;
    }

    protected function addSql(string $sql, array $params = [], array $types = []): void
    {
        throw new AbortMigration('Using method ::addSql() directly is forbidden to ensure database safety, use other methods please.');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }

    protected function addUnsafeSql(string $sql, array $params = [], array $types = [], int $statementTimeout = null): void
    {
        if (null !== $statementTimeout) {
            parent::addSql("SET statement_timeout TO '{$statementTimeout}s'");
            parent::addSql($sql, $params, $types);
            parent::addSql("SET statement_timeout TO '0'");
        } else {
            parent::addSql($sql, $params, $types);
        }
    }

    /**
     * @param string|string[] $columns
     */
    protected function addIndex(
        string $name,
        string $table,
        string|array $columns,
        bool $unique = false,
        string $usingMethod = '',
        string $where = '',
    ): void {
        $columns = \is_string($columns) ? [$columns] : $columns;

        if (\strlen($name) > 63) {
            throw new AbortMigration('Index name is too long. Please shorten it');
        }

        $this->addUnsafeSql("SET statement_timeout TO '0'");
        $this->addUnsafeSql("SET lock_timeout TO '0'");
        $this->addUnsafeSql(sprintf('CREATE %s CONCURRENTLY %s ON %s %s(%s)%s',
            $unique ? 'UNIQUE INDEX' : 'INDEX',
            $name,
            $table,
            $usingMethod ? "USING $usingMethod" : '',
            implode(',', $columns),
            $where ? " WHERE $where" : '',
        ));
        $this->addUnsafeSql("SET lock_timeout TO '3s'");
    }

    protected function dropIndex(string $name): void
    {
        $this->addUnsafeSql(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $name));
    }

    protected function renameIndex(string $from, string $to): void
    {
        $this->addUnsafeSql(sprintf('ALTER INDEX %s RENAME TO %s', $from, $to));
    }

    protected function addColumn(string $table, string $name, string $type, string $defaultValue = null, bool $nullable = true): void
    {
        if (null !== $defaultValue && u($defaultValue)->trim()->ignoreCase()->equalsTo('NULL')) {
            throw new AbortMigration(__METHOD__.' requires the usage of null PHP value instead of string one as default value.');
        }

        $this->addUnsafeSql(sprintf('ALTER TABLE %s ADD COLUMN %s %s DEFAULT %s%s',
            $table,
            $name,
            $type,
            $defaultValue ?? 'NULL',
            $nullable ? '' : ' NOT NULL',
        ), statementTimeout: 5);
    }

    protected function dropColumn(string $table, string $name): void
    {
        $this->addUnsafeSql(sprintf('ALTER TABLE %s DROP %s',
            $table,
            $name
        ));
    }

    protected function setDefaultOnColumn(string $table, string $name, string $value): void
    {
        $this->addUnsafeSql(sprintf('ALTER TABLE %s ALTER %s SET DEFAULT %s',
            $table,
            $name,
            $value,
        ), statementTimeout: 5);
    }

    protected function dropDefaultOnColumn(string $table, string $name): void
    {
        $this->addUnsafeSql(sprintf('ALTER TABLE %s ALTER %s DROP DEFAULT',
            $table,
            $name,
        ));
    }

    protected function setColumnNullable(string $table, string $name): void
    {
        $this->addUnsafeSql(sprintf('ALTER TABLE %s ALTER %s DROP NOT NULL',
            $table,
            $name,
        ));
    }

    protected function setColumnNotNullable(string $table, string $name): void
    {
        $constraintName = sprintf('chk_null_%s_%s', $table, $name);
        $this->addUnsafeSql(sprintf('ALTER TABLE %s ADD CONSTRAINT "%s" CHECK (%s IS NOT NULL) NOT VALID',
            $table,
            $constraintName,
            $name,
        ));
        $this->addUnsafeSql("SET statement_timeout TO '0'");
        $this->addUnsafeSql(sprintf('ALTER TABLE %s VALIDATE CONSTRAINT "%s"', $table, $constraintName));
        $this->addUnsafeSql("SET statement_timeout TO '5s'");
        $this->addUnsafeSql(sprintf('ALTER TABLE %s ALTER %s SET NOT NULL',
            $table,
            $name,
        ));
        $this->addUnsafeSql(sprintf('ALTER TABLE %s DROP CONSTRAINT "%s"',
            $table,
            $constraintName,
        ));
        $this->addUnsafeSql("SET statement_timeout TO '0'");
    }

    protected function commentOnColumn(string $table, string $name, ?string $comment): void
    {
        $this->addUnsafeSql(sprintf('COMMENT ON COLUMN %s.%s IS %s',
            $table,
            $name,
            null === $comment ? 'NULL' : "'$comment'"
        ));
    }

    /**
     * @param string[] $columnDefinitions
     */
    protected function createTable(string $table, array $columnDefinitions): void
    {
        foreach ($columnDefinitions as $columnDefinition) {
            if (u($columnDefinition)->collapseWhitespace()->ignoreCase()->containsAny('foreign key')) {
                throw new AbortMigration('It\'s not possible to add a foreign key in safe way while creating a table: please use the dedicated method to create a foreign key instead.');
            }
        }

        $this->addUnsafeSql(sprintf("CREATE TABLE %s (\n%s\n)",
            $table,
            implode(",\n", $columnDefinitions),
        ));
    }

    /**
     * @param string|null $options Can be used to add things like:
     *                             [ ON DELETE|ON UPDATE referential_action ] [ DEFERRABLE|NOT DEFERRABLE ] [ INITIALLY DEFERRED|INITIALLY IMMEDIATE ]
     */
    protected function addForeignKey(string $table, string $name, string $column, string $referenceTable, string $referenceColumn, string $options = null): void
    {
        $this->addUnsafeSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s(%s)%s NOT VALID',
            $table,
            $name,
            $column,
            $referenceTable,
            $referenceColumn,
            $options ? " $options" : ''
        ));
        $this->addUnsafeSql(sprintf('ALTER TABLE %s VALIDATE CONSTRAINT %s', $table, $name));
    }

    protected function renameConstraint(string $table, string $from, string $to): void
    {
        $this->addUnsafeSql(sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s',
            $table,
            $from,
            $to,
        ));
    }
}
