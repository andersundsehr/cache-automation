<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Overwrite;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Context\Context;
use AUS\CacheAutomation\Dto\SelectBy;
use AUS\CacheAutomation\Service\AutoCacheTagService;
use Doctrine\DBAL\Exception\NoKeyValue;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ForwardCompatibility\DriverResultStatement;
use Doctrine\DBAL\ForwardCompatibility\DriverStatement;
use Doctrine\DBAL\ForwardCompatibility\Result;
use Doctrine\DBAL\ParameterType;
use Exception;
use Generator;
use IteratorAggregate;
use PDO;
use Traversable;

/**
 * @implements IteratorAggregate<mixed, mixed>
 */
final class DoctrineResult implements IteratorAggregate, DriverStatement, DriverResultStatement
{
    private int $fetchMode = FetchMode::MIXED;

    private AutoCacheTagService $autoCacheTagService;

    private readonly int $currentTimeStamp;

    /**
     * @param Result<array<int|string, string|int|float|bool|null>> $result
     */
    public function __construct(private readonly Result $result, private readonly ?string $mainTableName, private readonly SelectBy $selectBy)
    {
        $this->currentTimeStamp = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
    }

    /**
     * @param array<string|int, mixed> $row
     */
    private function addCacheTags(array $row): void
    {
        $this->autoCacheTagService ??= AutoCacheTagService::getSingleton();
        if ($this->mainTableName) {
            $this->autoCacheTagService->addUsage($this->mainTableName, $row['uid'] ?? 0);
        }

        foreach ($this->selectBy->startTimes as $startTimeField) {
            $this->autoCacheTagService->maxLifeTime($row[$startTimeField] ?? null);
        }

        foreach ($this->selectBy->endTimes as $endTimeField) {
            $this->autoCacheTagService->maxLifeTime($row[$endTimeField] ?? null);
        }
    }

    /**
     * @param array<int|string, int|bool|float|string> $row
     */
    private function needsFilter(array &$row): bool
    {
        $this->addCacheTags($row);

        foreach ($this->selectBy->filterByStartTimes as $startTimeField) {
            $startTime = $row[$startTimeField] ?? 0;
            if (!$startTime) {
                continue;
            }

            if ($startTime <= $this->currentTimeStamp) {
                continue;
            }

            return true;
        }

        foreach ($this->selectBy->allFields as $field) {
            if (!array_key_exists($field, $row)) {
                // could be null if MIN or equivalent is used (but it should never be unset)
                throw new Exception('field not found, ' . $field);
            }

            unset($row[$field]);
        }

        return false;
    }

    ///////////////
    /// here starts the implementation of the Result interface:
    ///////////////


    /**
     * @return \Generator<array<int|string, string|int|float|bool|null>>
     */
    public function getIterator(): Generator
    {
        while (($result = $this->fetch()) !== false) {
            yield $result;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated Use Result::free() instead.
     */
    public function closeCursor()
    {
        return $this->result->closeCursor();
    }

    /**
     * {@inheritDoc}
     */
    public function columnCount(): int
    {
        return $this->result->columnCount();
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated Use one of the fetch- or iterate-related methods.
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null)
    {
        $this->fetchMode = $fetchMode;
        $result = $this->result->setFetchMode($this->fetchMode, $arg2, $arg3);

        $this->result->setFetchMode($this->fetchMode === FetchMode::NUMERIC ? FetchMode::MIXED : $fetchMode, $arg2, $arg3);

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated Use fetchNumeric(), fetchAssociative() or fetchOne() instead.
     */
    public function fetch($fetchMode = null, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        do {
            $funcGetArgs = func_get_args();
            array_shift($funcGetArgs);
            $result = $this->result->fetch($fetchMode === FetchMode::NUMERIC ? FetchMode::MIXED : $fetchMode, ...$funcGetArgs);
            if ($result === false) {
                return false;
            }
        } while ($this->needsFilter($result));

        if (($fetchMode ?? $this->fetchMode) === FetchMode::NUMERIC) {
            return array_filter($result, is_int(...), ARRAY_FILTER_USE_KEY);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated Use fetchAllNumeric(), fetchAllAssociative() or fetchFirstColumn() instead.
     */
    public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null)
    {
        $rows = $this->result->fetchAll($fetchMode === FetchMode::NUMERIC ? FetchMode::MIXED : $fetchMode, $fetchArgument, $ctorArgs);
        $result = [];
        foreach ($rows as $row) {
            if ($this->needsFilter($row)) {
                continue;
            }

            if (($fetchMode ?? $this->fetchMode) === FetchMode::NUMERIC) {
                $result[] = array_filter($row, is_int(...), ARRAY_FILTER_USE_KEY);
                continue;
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated Use fetchOne() instead.
     */
    public function fetchColumn($columnIndex = 0)
    {
        $row = $this->fetch(FetchMode::NUMERIC);

        if ($row === false) {
            return false;
        }

        return $row[$columnIndex] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchNumeric()
    {
        return $this->fetch(PDO::FETCH_NUM);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAssociative()
    {
        return $this->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchOne()
    {
        $row = $this->fetchNumeric();

        if ($row === false) {
            return false;
        }

        return $row[0];
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAllNumeric(): array
    {
        $rows = [];

        while (($row = $this->fetchNumeric()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAllAssociative(): array
    {
        $rows = [];

        while (($row = $this->fetchAssociative()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAllKeyValue(): array
    {
        $this->ensureHasKeyValue();
        $data = [];

        foreach ($this->fetchAllNumeric() as [$key, $value]) {
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAllAssociativeIndexed(): array
    {
        $data = [];

        foreach ($this->fetchAllAssociative() as $row) {
            $data[array_shift($row)] = $row;
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchFirstColumn(): array
    {
        $rows = [];

        while (($row = $this->fetchOne()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     *
     * @return Traversable<int,array<int,mixed>>
     */
    public function iterateNumeric(): Traversable
    {
        while (($row = $this->fetchNumeric()) !== false) {
            yield $row;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,array<string,mixed>>
     */
    public function iterateAssociative(): Traversable
    {
        while (($row = $this->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<mixed,mixed>
     */
    public function iterateKeyValue(): Traversable
    {
        $this->ensureHasKeyValue();

        foreach ($this->iterateNumeric() as [$key, $value]) {
            yield $key => $value;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<mixed,array<string,mixed>>
     */
    public function iterateAssociativeIndexed(): Traversable
    {
        foreach ($this->iterateAssociative() as $row) {
            yield array_shift($row) => $row;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int,mixed>
     */
    public function iterateColumn(): Traversable
    {
        while (($value = $this->fetchOne()) !== false) {
            yield $value;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rowCount(): int
    {
        // is not the best way, but we need to use the filter method otherwise the count is wrong.
        return count($this->fetchAllNumeric());
    }

    public function free(): void
    {
        $this->closeCursor();
    }

    private function ensureHasKeyValue(): void
    {
        $columnCount = $this->columnCount();

        if ($columnCount < 2) {
            throw NoKeyValue::fromColumnCount($columnCount);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated This feature will no longer be available on Result object in 3.0.x version.
     */
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        return $this->result->bindValue($param, $value, $type);
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated This feature will no longer be available on Result object in 3.0.x version.
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null)
    {
        return $this->result->bindParam($param, $variable, $type, $length);
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated The error information is available via exceptions.
     */
    public function errorCode()
    {
        return $this->result->errorCode();
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated The error information is available via exceptions.
     */
    public function errorInfo()
    {
        return $this->result->errorInfo();
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated This feature will no longer be available on Result object in 3.0.x version.
     */
    public function execute($params = null)
    {
        return $this->result->execute($params);
    }
}
