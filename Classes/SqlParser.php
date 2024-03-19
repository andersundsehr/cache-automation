<?php

declare(strict_types=1);

namespace AUS\CacheAutomation;

use AUS\CacheAutomation\Dto\SqlParseResult;
use Exception;
use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SqlParser implements SingletonInterface
{
    private readonly PhpFrontend $cache;

    /**
     * @param array<string, array<string, true>>|null $tablesInDb
     */
    public function __construct(
        ?PhpFrontend $cache = null,
        private ?array $tablesInDb = null,
    ) {
        $this->cache = $cache ?? GeneralUtility::makeInstance(CacheManager::class)->getCache('core');
        $this->tablesInDb ??= $this->getDbTableAndFields();
    }

    public function parseSql(string $sql): ?SqlParseResult
    {
        $cacheHash = 'sqlparser_' . md5($sql);
        if ($this->cache->has($cacheHash)) {
            return $this->cache->require($cacheHash);
        }

        try {
            $result = $this->parseSqlInner($sql);
        } catch (Exception $exception) {
            throw new Exception('Error parsing sql: ' . $sql . ' :::::: ' . $exception->getMessage(), 0, $exception);
        }

        $this->cache->set($cacheHash, 'return ' . var_export($result, true) . ';');
        return $result;
    }

    private function parseSqlInner(string $sql): ?SqlParseResult
    {
        /** @var array<string, string> $aliasTables */
        $aliasTables = [];
        /** @var list<string> $possibleTables */
        $possibleTables = [];

        // from with table is the uid field
        $mainTable = null;

        /** @var array<string, true> $fields */
        $fields = [];
        $parser = new Parser($sql);
        foreach ($parser->statements as $statement) {
            if (!$statement instanceof SelectStatement) {
                throw new Exception('Unexpected statement type');
            }

            foreach ($statement->from ?? [] as $table) {
                $possibleTables[] = $table->table ?? throw new Exception('From without table');
                if ($table->alias) {
                    $aliasTables[$table->alias] = $table->table;
                }
            }

            foreach ($statement->join ?? [] as $join) {
                $possibleTables[] = $join->expr->table ?? throw new Exception('Join without table');
                if ($join->expr->alias) {
                    $aliasTables[$join->expr->alias] = $join->expr->table;
                }

                foreach ($join->on ?? [] as $item) {
                    // debug phpstan type
                    $fields = [...$fields, ...$this->findTablesInIdentifiers($possibleTables, $aliasTables, $item->identifiers)];
                }
            }

            $selectedFields = [];

            // last * or uid wins the mainTable
            foreach (array_reverse($statement->expr ?? []) as $select) {
                if ($select->table) {
                    $selectedFields[] = $select->table . '.' . ($select->column ?? '*');
                }

                if ($mainTable) {
                    continue;
                }

                if (!($select->column === 'uid' || str_ends_with($select->expr ?? '', '*') || str_ends_with($select->expr ?? '', 'uid'))) {
                    continue;
                }

                if ($select->table) {
                    $mainTable = $aliasTables[$select->table] ?? $select->table;
                }
            }

            // it could be that the * was without a table, so we need to check all tables:
            $mainTable ??= $this->getUniqueTableForField($possibleTables, 'uid') ?? $mainTable;

            foreach ($statement->where ?? [] as $item) {
                $fields = [...$fields, ...$this->findTablesInIdentifiers($possibleTables, $aliasTables, $item->identifiers)];
            }

            foreach ($statement->group ?? [] as $item) {
                $expr = $item->expr->expr ?? throw new Exception('Group without expr');
                foreach (Condition::parse($parser, Lexer::getTokens($expr)) as $condition) {
                    $fields = [...$fields, ...$this->findTablesInIdentifiers($possibleTables, $aliasTables, $condition->identifiers)];
                }
            }

            foreach ($statement->having ?? [] as $item) {
                $fields = [...$fields, ...$this->findTablesInIdentifiers($possibleTables, $aliasTables, $item->identifiers)];
            }

            foreach ($statement->order ?? [] as $item) {
                $expr = $item->expr->expr ?? throw new Exception('Order without expr');
                foreach (Condition::parse($parser, Lexer::getTokens($expr)) as $condition) {
                    // in ORDER BY the "Ambiguous field name" can be limited by the selected fields 😮 see "sys_note" Testcase (crdate)
                    $fields = [...$fields, ...$this->findTablesInIdentifiers($possibleTables, $aliasTables, $condition->identifiers, $selectedFields)];
                }
            }
        }

        $mainTable = $aliasTables[$mainTable] ?? $mainTable;

        $isRelational = $this->isRelational($fields, $possibleTables);

        if (!$mainTable) {
            return null;
        }

        return SqlParseResult::fromStrings($mainTable, array_keys($fields), $isRelational);
    }

    /**
     * @param list<string> $possibleTables
     * @param array<string, string> $aliasTables
     * @param list<string> $identifiers
     * @param list<string> $selectedFields
     * @return array<string, true>
     */
    private function findTablesInIdentifiers(array $possibleTables, array $aliasTables, array $identifiers, array $selectedFields = []): array
    {
        $fields = [];
        $count = count($identifiers);
        for ($i = 0; $i < $count; $i++) {
            $fields = [...$fields, ...$this->addifFound($identifiers[$i - 1] ?? null, $identifiers[$i], $possibleTables, $aliasTables, $selectedFields)];
        }

        return $fields;
    }

    /**
     * @param list<string> $possibleTables
     * @param array<string, string> $aliasTables
     * @param list<string> $selectedFields
     * @return array<string, true>
     */
    private function addifFound(?string $table, string $field, array $possibleTables, array $aliasTables, array $selectedFields): array
    {
        $table = $aliasTables[$table] ?? $table ?? '';

        if ($this->fieldExistsInDatabase($table, $field)) {
            return [$table . '.' . $field => true];
        }

        $foundTable = $this->getUniqueTableForField($possibleTables, $field, $selectedFields);
        if ($foundTable) {
            return [$foundTable . '.' . $field => true];
        }

        return [];
    }

    /**
     * @param array<string, true> $fields
     * @param list<string> $possibleTables
     */
    private function isRelational(array $fields, array $possibleTables): bool
    {
        foreach ($possibleTables as $table) {
            if (
                isset($fields[$table . '.uid'])
                || isset($fields[$table . '.uid_foreign'])
                || isset($fields[$table . '.uid_local'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $possibleTables
     * @param list<string> $selectedFields
     * @throws Exception
     */
    private function getUniqueTableForField(array $possibleTables, string $field, array $selectedFields = []): ?string
    {
        $fieldMatchedTables = [];
        foreach ($possibleTables as $possibleTable) {
            if ($this->fieldExistsInDatabase($possibleTable, $field)) {
                if ($selectedFields) {
                    if (in_array($possibleTable . '.' . $field, $selectedFields, true)) {
                        $fieldMatchedTables[] = $possibleTable;
                        continue;
                    }

                    if (in_array($possibleTable . '.*', $selectedFields, true)) {
                        $fieldMatchedTables[] = $possibleTable;
                        continue;
                    }

                    continue;
                }

                $fieldMatchedTables[] = $possibleTable;
            }
        }

        if (!$fieldMatchedTables) {
            return null;
        }

        if (count($fieldMatchedTables) > 1) {
            throw new Exception('Ambiguous field name: ' . implode(', ', $fieldMatchedTables) . ' field:' . $field);
        }

        return $fieldMatchedTables[0];
    }

    private function fieldExistsInDatabase(string $table, string $field): bool
    {
        return $this->tablesInDb[$table][$field] ?? false;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function getDbTableAndFields(): array
    {
        if ($this->cache->has('sqlparser_tablesInDb')) {
            return $this->cache->require('sqlparser_tablesInDb');
        }

        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $sql = $sqlReader->getTablesDefinitionString(true);
        $schemaMigrator = GeneralUtility::makeInstance(SchemaMigrator::class);
        $sqlCreates = $sqlReader->getCreateTableStatementArray($sql);

        $tables = $schemaMigrator->parseCreateTableStatements($sqlCreates);

        $result = [];
        foreach ($tables as $table) {
            foreach ($table->getColumns() as $column) {
                $result[$table->getName()][$column->getName()] = true;
            }
        }

        $this->cache->set('sqlparser_tablesInDb', 'return ' . var_export($result, true) . ';');

        return $result;
    }
}
