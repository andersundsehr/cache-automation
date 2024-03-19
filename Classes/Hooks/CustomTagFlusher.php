<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Hooks;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

final class CustomTagFlusher
{
    /** @var array<string, true> */
    private array $alreadyFlushed = [];

    /**
     * @param array<string, mixed> $fieldArray
     * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
     */

    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        match ($status) {
            'new' => $this->new($table),
            'update' => $this->update($table, $fieldArray),
            default => null,
        };
    }

    private function new(string $table): void
    {
        // TODO use \AUS\CacheAutomation\Service\AutoCacheTagService::isTableExcluded
        $this->flush($table . '--new');
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    private function update(string $table, array $fieldArray): void
    {
        // TODO use \AUS\CacheAutomation\Service\AutoCacheTagService::isTableExcluded maybe also isFieldExcluded?
        foreach (array_keys($fieldArray) as $field) {
            $this->flush($table . '-' . $field);
        }
    }

    private function flush(string $tag): void
    {
        if (isset($this->alreadyFlushed[$tag])) {
            return;
        }

        GeneralUtility::makeInstance(CacheManager::class)->flushCachesByTag($tag);
        $this->alreadyFlushed[$tag] = true;
    }
}
