<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Hooks;

use AUS\CacheAutomation\Configuration;
use AUS\CacheAutomation\Metrics;
use AUS\CacheAutomation\Service\AutoCacheTagService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class CustomTagFlusher
{
    /** @var array<string, true> */
    private array $alreadyFlushed = [];

    public function __construct(private readonly AutoCacheTagService $autoCacheTagService)
    {
    }

    /**
     * @param array<string, mixed> $fieldArray
     * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
     */

    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($this->autoCacheTagService->isTableExcluded($table)) {
            return;
        }

        match ($status) {
            'new' => $this->new($table),
            'update' => $this->update($table, $fieldArray),
            default => null,
        };
    }

    /**
     * clear cache for list pages
     */
    private function new(string $table): void
    {
        if (!Configuration::get('flushCacheOnNew')) {
            return;
        }

        $this->flushTags($table . '--new');
    }

    /**
     * clear cache for list pages where fields are conditional fields
     *
     * @param array<string, mixed> $fieldArray
     */
    private function update(string $table, array $fieldArray): void
    {
        if (!Configuration::get('flushCacheOnConditionalField')) {
            return;
        }

        $tags = [];
        foreach (array_keys($fieldArray) as $field) {
            $tags[] = $table . '-' . $field;
        }

        $this->flushTags(...$tags);
    }

    private function flushTags(string ...$tags): void
    {
        $toFlush = [];
        foreach ($tags as $tag) {
            if (isset($this->alreadyFlushed[$tag])) {
                continue;
            }

            $this->alreadyFlushed[$tag] = true;
            $toFlush[] = $tag;
        }

        if (!$toFlush) {
            return;
        }

        Metrics::flushTags($toFlush);
        if (Configuration::get('metricsOnly')) {
            return;
        }

        GeneralUtility::makeInstance(CacheManager::class)->flushCachesByTags($toFlush);
    }
}
