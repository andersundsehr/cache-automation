<?php

declare(strict_types=1);

namespace AUS\CacheAutomation;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Metrics
{
    public static function addCacheTime(int $cacheTimeoutToSet): void
    {
        self::print(__FUNCTION__ . ': ' . $cacheTimeoutToSet);
    }

    /**
     * @param list<string> $tags
     */
    public static function addTagsToCache(array $tags): void
    {
        self::print(__FUNCTION__ . ': ' . count($tags) . ' ' . implode(', ', $tags));
    }

    /**
     * @param list<string> $tags
     */
    public static function flushTags(array $tags): void
    {
        self::print(__FUNCTION__ . ': ' . count($tags) . ' ' . implode(', ', $tags));
    }

    private static function print(string $line): void
    {
        $id = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp') . '.txt';
        file_put_contents('/app/requestID' . $id, $line . PHP_EOL, FILE_APPEND);
    }
}
