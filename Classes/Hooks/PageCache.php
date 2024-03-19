<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Hooks;

use AUS\CacheAutomation\Service\AutoCacheTagService;

final class PageCache
{
    /**
     * needs replacement with AfterCacheableContentIsGeneratedEvent for TYPO3 v12
     */
    public function contentPostProc(): void
    {
        AutoCacheTagService::getSingleton()->trigger();
    }
}
