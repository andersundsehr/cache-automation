<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Overwrite;

use AUS\CacheAutomation\Dto\SelectBy;
use AUS\CacheAutomation\Dto\SqlParseResult;
use AUS\CacheAutomation\Service\AutoCacheTagService;
use AUS\CacheAutomation\SqlParser;
use Doctrine\DBAL\ForwardCompatibility\Result;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Typo3QueryBuilder extends QueryBuilder
{
    public function executeQuery(): \Doctrine\DBAL\Result
    {
        $selectBy = $this->selectBy();

        $sqlParseResult = $this->doSql($this->getSQL());

        $this->restrictionContainer->removeByType(StartTimeRestriction::class);

        $result = parent::executeQuery();
        assert($result instanceof Result);

        return new DoctrineResult(
            $result,
            $sqlParseResult?->mainTable,
            $selectBy,
        );
    }

    private function doSql(string $sql): ?SqlParseResult
    {
        $makeInstance = GeneralUtility::makeInstance(SqlParser::class);
        assert($makeInstance instanceof SqlParser);
        $sqlParseResult = $makeInstance->parseSql($sql);
        if (!$sqlParseResult) {
            return null;
        }

        $tableName = $sqlParseResult->mainTable;

        if (!isset($GLOBALS['TCA'][$tableName])) {
            return $sqlParseResult;
        }

        $autoCacheTagService = AutoCacheTagService::getSingleton();

        if (!$sqlParseResult->isRelational) {
            $autoCacheTagService->addIsList($tableName);
        }

        foreach ($sqlParseResult->conditionalFields as $field) {
            $autoCacheTagService->addFieldUsage(...explode('.', $field));
        }

        return $sqlParseResult;
    }

    private function selectBy(): SelectBy
    {
        $filterBy = [
            'starttime' => [],
        ];
        $aliasFields = [
            'starttime' => [],
            'endtime' => [],
        ];
        $autoCacheTagService = AutoCacheTagService::getSingleton();


        foreach ($this->getQueriedTables() as $tableAlias => $queriedTable) {
            // we can not look into the restriction container, because it is private. So we Build the Expressions and look for the fields
            $sqlPart = null;

            $hiddenFieldName = $GLOBALS['TCA'][$queriedTable]['ctrl']['enablecolumns']['disabled'] ?? null;
            if ($hiddenFieldName) {
                // are the current restrictions using the hidden field?
                $sqlPart = $this->restrictionContainer->buildExpression([$tableAlias => $queriedTable], $this->expr())->__toString();
                if (str_contains($sqlPart, (string) $hiddenFieldName)) {
                    $autoCacheTagService->addFieldUsage($queriedTable, $hiddenFieldName);
                }
            }

            $startTimeFieldName = $GLOBALS['TCA'][$queriedTable]['ctrl']['enablecolumns']['starttime'] ?? null;
            if ($startTimeFieldName) {
                $alias = 'starttime__' . $queriedTable;
                $aliasFields['starttime'][] = $alias;
                $this->addSelect($tableAlias . '.' . $startTimeFieldName . ' AS ' . $alias);

                // are the current restrictions using the starttime field?
                $sqlPart ??= $this->restrictionContainer->buildExpression([$tableAlias => $queriedTable], $this->expr())->__toString();
                if (str_contains($sqlPart, (string) $startTimeFieldName)) {
                    $filterBy['starttime'][] = $alias;
                    $autoCacheTagService->addFieldUsage($queriedTable, $startTimeFieldName);
                }
            }

            $endTimeFieldName = $GLOBALS['TCA'][$queriedTable]['ctrl']['enablecolumns']['endtime'] ?? null;
            if ($endTimeFieldName) {
                $alias = 'endtime__' . $queriedTable;
                $aliasFields['endtime'][] = $alias;
                $this->addSelect($tableAlias . '.' . $endTimeFieldName . ' AS ' . $alias);
            }
        }

        return new SelectBy(
            $aliasFields['starttime'],
            $aliasFields['endtime'],
            $filterBy['starttime'],
        );
    }
}
