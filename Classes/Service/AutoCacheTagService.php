<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Service;

use AUS\CacheAutomation\Configuration;
use AUS\CacheAutomation\Metrics;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

final class AutoCacheTagService
{
    /**
     * @var array<string, array<int, bool>>
     */
    private array $rowUsages = [];

    /**
     * @var array<string, true>
     */
    private array $tableUsages = [];

    /**
     * @var array<string, array<string, bool>>
     */
    private array $fieldUsages = [];

    private int $lifeTime = PHP_INT_MAX;

    private static ?AutoCacheTagService $instance = null;

    public function __construct(
        private readonly FrontendInterface $runtimeCache,
    ) {
    }

    public static function getSingleton(): self
    {
        return self::$instance ??= GeneralUtility::makeInstance(self::class);
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function maxLifeTime(int|null $timestamp): void
    {
        if (!$timestamp) {
            return;
        }

        if ($timestamp <= GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp')) {
            return;
        }

        if ($this->lifeTime <= $timestamp) {
            return;
        }

        $this->lifeTime = $timestamp;
        $this->setCacheTimeOut();
    }

    public function addFieldUsage(string $table, string $field): void
    {
        $this->fieldUsages[$table][$field] = true;
    }

    public function addIsList(string $table): void
    {
        $this->tableUsages[$table] = true;
    }

    public function addUsage(string $table, int $uid): void
    {
        $this->rowUsages[$table][$uid] = true;
    }

    /**
     * this method adds all tags to the TSFE
     * if to many tags are from the same table the complete table is added
     * some tables are ignored like: sys_file_metadata, sys_file_reference, sys_file_storage, sys_file, pages, cache_*, be_*, fe_*
     */
    public function trigger(): void
    {
        $typoScriptFrontendController = $GLOBALS['TSFE'] ?? null;
        if (!$typoScriptFrontendController instanceof TypoScriptFrontendController) {
            return;
        }

        $tags = $this->createTags();

        Metrics::addTagsToCache($tags);
        if (Configuration::get('metricsOnly')) {
            return;
        }

        $typoScriptFrontendController->addCacheTags($tags);
    }

    /**
     * if to many tags are from the same table the complete table is added only once
     *
     * @return list<string>
     */
    private function createTags(): array
    {
        $tags = [];
        foreach ($this->rowUsages as $table => $uids) {
            if ($this->isTableExcluded($table)) {
                continue;
            }

            unset($uids[0]);
            foreach (array_keys($uids) as $uid) {
                if ($table === 'pages') {
                    $table = 'pageId';
                }

                if (Configuration::get('flushCacheOnUid')) {
                    $tags[] = $table . '_' . $uid;
                }
            }
        }

        foreach (array_keys($this->tableUsages) as $table) {
            if ($this->isTableExcluded($table)) {
                continue;
            }

            if (Configuration::get('flushCacheOnNew')) {
                $tags[] = $table . '--new';
            }
        }

        foreach ($this->fieldUsages as $table => $fields) {
            if ($this->isTableExcluded($table)) {
                continue;
            }

            unset($fields['uid']);

            foreach (array_keys($fields) as $field) {
                if (Configuration::get('flushCacheOnConditionalField')) {
                    $tags[] = $table . '-' . $field;
                }
            }
        }

        return $tags;
    }

    public function isTableExcluded(string $table): bool
    {
        if ($GLOBALS['TCA'][$table]['ctrl']['is_static'] ?? false) {
            return true;
        }

        if ($GLOBALS['TCA'][$table]['ctrl']['readOnly'] ?? false) {
            return true;
        }

        if ($table === 'tt_content') {
            // has pageId rule
            return true;
        }

        if (str_starts_with($table, 'be_')) {
            return true;
        }

        if ($table === 'sys_file') {
            return false;
        }

        if (str_starts_with($table, 'sys_')) {
            return true;
        }

        // is not defined:
        return !isset($GLOBALS['TCA'][$table]);
    }

    private function setCacheTimeOut(): void
    {
        $typoScriptFrontendController = $GLOBALS['TSFE'] ?? null;
        if (!$typoScriptFrontendController instanceof TypoScriptFrontendController) {
            return;
        }

        $cacheTimeout = $typoScriptFrontendController->get_cache_timeout();
        if (!$cacheTimeout) {
            return;
        }

        $this->lifeTime = min($this->lifeTime, GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp') + $cacheTimeout);

        $cacheTimeoutToSet = $this->lifeTime - GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');

        Metrics::addCacheTime($cacheTimeoutToSet);

        if (Configuration::get('metricsOnly')) {
            return;
        }

        $this->runtimeCache->set('cacheLifeTimeForPage_' . $typoScriptFrontendController->id, $cacheTimeoutToSet); // TYPO3 12

        $runtimeCache = GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime'); // no DI in Core for Cache in TYPO3 11
        $runtimeCache->set('core-tslib_fe-get_cache_timeout', $cacheTimeoutToSet); // TYPO3 11
    }
}
