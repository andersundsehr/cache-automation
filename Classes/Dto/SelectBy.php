<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Dto;

final readonly class SelectBy
{
    /** @var list<string> */
    public array $allFields;

    /**
     * @param list<string> $startTimes
     * @param list<string> $endTimes
     * @param list<string> $filterByStartTimes
     */
    public function __construct(
        public array $startTimes,
        public array $endTimes,
        public array $filterByStartTimes,
    ) {
        $this->allFields = array_values(
            array_unique(
                [
                    ...$this->startTimes,
                    ...$this->endTimes,
                    ...$this->filterByStartTimes,
                ]
            )
        );
    }
}
