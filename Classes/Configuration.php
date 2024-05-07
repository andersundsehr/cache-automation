<?php

declare(strict_types=1);

namespace AUS\CacheAutomation;

use Exception;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Configuration
{
    /** @var array<array-key, string|bool|int|float>|null */
    private static ?array $configuration = null;

    /**
     * Returns the whole extension configuration or a specific key
     */
    public static function get(string $key): string
    {
        self::$configuration ??= GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('cache_automation');

        return (string)(self::$configuration[$key] ?? throw new Exception('Configuration key not found' . $key));
    }
}
