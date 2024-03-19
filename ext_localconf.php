<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use AUS\CacheAutomation\Overwrite\Typo3QueryBuilder;
use AUS\CacheAutomation\Hooks\CustomTagFlusher;
use AUS\CacheAutomation\Hooks\PageCache;

# can be removed if only TYPO3 v12 or higher is supported
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/class.tslib_fe.php']['contentPostProc-cached']['aus_cache_automation'] = PageCache::class . '->contentPostProc';

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['aus_cache_automation'] = CustomTagFlusher::class;


$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][QueryBuilder::class]['className'] = Typo3QueryBuilder::class;
