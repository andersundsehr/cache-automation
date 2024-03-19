<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Dto;

final readonly class SqlParseResult
{
    /**
     * @param list<string> $conditionalFields
     */
    private function __construct(
        public string $mainTable,
        public array $conditionalFields,
        public bool $isRelational,
    ) {
    }

    /**
     * @param list<string> $conditionalFields
     */
    public static function fromStrings(string $mainTable, array $conditionalFields, bool $isRelational): self
    {
        natcasesort($conditionalFields);
        $conditionalFields = array_values(array_unique($conditionalFields));

        return new self(
            $mainTable,
            $conditionalFields,
            $isRelational
        );
    }

    /**
     * @param array{mainTable: string, conditionalFields: list<string>, isRelational: bool} $array
     */
    public static function __set_state(array $array): self
    {
        return new self(...$array);
    }
}
