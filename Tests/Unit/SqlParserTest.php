<?php

declare(strict_types=1);

namespace AUS\CacheAutomation\Tests\Unit;

use AUS\CacheAutomation\Dto\Field;
use AUS\CacheAutomation\Dto\SqlParseResult;
use AUS\CacheAutomation\SqlParser;
use Exception;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;

#[CoversClass(SqlParser::class)]
#[CoversClass(SqlParseResult::class)]
final class SqlParserTest extends TestCase
{
    private static function getCache(): PhpFrontend
    {
        return new NullFrontend('test');
    }

    /**
     * @return array<string, array<string, true>>
     */
    private static function getDbTableAndFields(): array
    {
        return require __DIR__ . '/../dbTableAndFields.php';
    }

    // a test that tests that SQlparser throws if a diffrent type of query is passed. e.g. INSERT query
    #[Test]
    public function parseSqlThrowsOnInsertQuery(): void
    {
        $sql = 'INSERT INTO `pages` (`uid`, `title`) VALUES (1, "test")';
        $sqlParser = new SqlParser(self::getCache(), self::getDbTableAndFields());
        $this->expectException(Exception::class);
        $sqlParser->parseSql($sql);
    }

    #[Test]
    #[DataProvider('parseSqlDataProvider')]
    public function parseSql(string $sql, ?SqlParseResult $expectedResult): void
    {
        $sqlParser = new SqlParser(self::getCache(), self::getDbTableAndFields());
        $result = $sqlParser->parseSql($sql);
        if ($expectedResult && $result) {
            self::assertSame($expectedResult->mainTable, $result->mainTable);
            self::assertEquals($expectedResult->conditionalFields, $result->conditionalFields);
            self::assertSame($expectedResult->isRelational, $result->isRelational);
        }

        self::assertEquals($expectedResult, $result);
    }

    public static function parseSqlDataProvider(): Generator
    {
        yield 'relational select' => [
            'sql' => 'SELECT * FROM `pages` WHERE `uid` = :uid',
            'expectedResult' => SqlParseResult::fromStrings(
                'pages',
                [
                    'pages.uid',
                ],
                true
            ),
        ];
        yield 'select 2 fields' => [
            'sql' => "SELECT * FROM tx_rampffairs_domain_model_fair WHERE hidden=0 AND deleted=0",
            'expectedResult' => SqlParseResult::fromStrings(
                'tx_rampffairs_domain_model_fair',
                [
                    'tx_rampffairs_domain_model_fair.hidden',
                    'tx_rampffairs_domain_model_fair.deleted',
                ],
                false
            ),
        ];
        yield 'select multiple fields' => [
            'sql' => <<<EOF
SELECT `pages`.*
FROM `pages` `pages`
WHERE (`pages`.`doktype` = :dcValue1)
  AND (`pages`.`doktype` = :dcValue2)
  AND (`pages`.`sys_language_uid` IN (0, -1))
  AND (`pages`.`t3ver_oid` = 0)
  AND ((`pages`.`deleted` = 0) AND (`pages`.`t3ver_state` <= 0) AND (`pages`.`t3ver_wsid` = 0) AND
       ((`pages`.`t3ver_oid` = 0) OR (`pages`.`t3ver_state` = 4)) AND (`pages`.`hidden` = 0) AND
       (`pages`.`starttime` <= 1710315540) AND ((`pages`.`endtime` = 0) OR (`pages`.`endtime` > 1710315540)) AND
       (((`pages`.`fe_group` = '') OR (`pages`.`fe_group` IS NULL) OR (`pages`.`fe_group` = '0') OR
         (FIND_IN_SET('0', `pages`.`fe_group`)) OR (FIND_IN_SET('-1', `pages`.`fe_group`)))))
EOF
            ,
            'expectedResult' => SqlParseResult::fromStrings(
                'pages',
                [
                    'pages.doktype',
                    'pages.sys_language_uid',
                    'pages.t3ver_oid',
                    'pages.deleted',
                    'pages.t3ver_state',
                    'pages.t3ver_wsid',
                    'pages.t3ver_oid',
                    'pages.hidden',
                    'pages.starttime',
                    'pages.endtime',
                    'pages.fe_group',
                ],
                false
            ),
        ];
        yield 'relational select multiple fields tx_ table' => [
            'sql' => <<<EOF
SELECT `tx_rampfdownloads_domain_model_resourceproductfamily`.*
FROM `tx_rampfdownloads_domain_model_resourceproductfamily` `tx_rampfdownloads_domain_model_resourceproductfamily`
WHERE (`tx_rampfdownloads_domain_model_resourceproductfamily`.`uid` IN (:dcValue1))
  AND ((`tx_rampfdownloads_domain_model_resourceproductfamily`.`deleted` = 0) AND
       (`tx_rampfdownloads_domain_model_resourceproductfamily`.`hidden` = 0) AND
       (`tx_rampfdownloads_domain_model_resourceproductfamily`.`starttime` <= 1710315540) AND
       ((`tx_rampfdownloads_domain_model_resourceproductfamily`.`endtime` = 0) OR
        (`tx_rampfdownloads_domain_model_resourceproductfamily`.`endtime` > 1710315540)))
LIMIT 1
EOF
            ,
            'expectedResult' => SqlParseResult::fromStrings(
                'tx_rampfdownloads_domain_model_resourceproductfamily',
                [
                    'tx_rampfdownloads_domain_model_resourceproductfamily.uid',
                    'tx_rampfdownloads_domain_model_resourceproductfamily.deleted',
                    'tx_rampfdownloads_domain_model_resourceproductfamily.hidden',
                    'tx_rampfdownloads_domain_model_resourceproductfamily.starttime',
                    'tx_rampfdownloads_domain_model_resourceproductfamily.endtime',
                ],
                true
            ),
        ];
        yield 'relational select multiple fields MM table' => [
            'sql' => <<<EOF
SELECT `sys_file_reference`.*
FROM `sys_file_reference` `sys_file_reference`
WHERE (((`sys_file_reference`.`uid_foreign` = :dcValue1) AND (`sys_file_reference`.`tablenames` = :dcValue2)) AND
       (`sys_file_reference`.`fieldname` = :dcValue3))
  AND (`sys_file_reference`.`t3ver_oid` = 0)
  AND ((`sys_file_reference`.`deleted` = 0) AND (`sys_file_reference`.`t3ver_state` <= 0) AND
       (`sys_file_reference`.`t3ver_wsid` = 0) AND
       ((`sys_file_reference`.`t3ver_oid` = 0) OR (`sys_file_reference`.`t3ver_state` = 4)) AND
       (`sys_file_reference`.`hidden` = 0))
LIMIT 1
EOF
            ,
            'expectedResult' => SqlParseResult::fromStrings(
                'sys_file_reference',
                [
                    'sys_file_reference.uid_foreign',
                    'sys_file_reference.tablenames',
                    'sys_file_reference.fieldname',
                    'sys_file_reference.t3ver_oid',
                    'sys_file_reference.deleted',
                    'sys_file_reference.t3ver_state',
                    'sys_file_reference.t3ver_wsid',
                    'sys_file_reference.hidden',
                ],
                true
            ),
        ];
        yield 'relational select multiple fields MM table, with order by' => [
            'sql' => <<<EOF
SELECT `sys_file_reference`.*
FROM `sys_file_reference` `sys_file_reference`
WHERE (((`sys_file_reference`.`uid_foreign` = :dcValue1) AND (`sys_file_reference`.`tablenames` = :dcValue2)) AND
       (`sys_file_reference`.`fieldname` = :dcValue3))
  AND (`sys_file_reference`.`t3ver_oid` = 0)
  AND ((`sys_file_reference`.`deleted` = 0) AND (`sys_file_reference`.`t3ver_state` <= 0) AND
       (`sys_file_reference`.`t3ver_wsid` = 0) AND
       ((`sys_file_reference`.`t3ver_oid` = 0) OR (`sys_file_reference`.`t3ver_state` = 4)) AND
       (`sys_file_reference`.`hidden` = 0))
ORDER BY `sys_file_reference`.`sorting_foreign` ASC
EOF
            ,
            'expectedResult' => SqlParseResult::fromStrings(
                'sys_file_reference',
                [
                    'sys_file_reference.uid_foreign',
                    'sys_file_reference.tablenames',
                    'sys_file_reference.fieldname',
                    'sys_file_reference.t3ver_oid',
                    'sys_file_reference.deleted',
                    'sys_file_reference.t3ver_state',
                    'sys_file_reference.t3ver_wsid',
                    'sys_file_reference.hidden',
                    'sys_file_reference.sorting_foreign',
                ],
                true,
            ),
        ];
        yield 'JOIN on pages' => [
            'sql' => <<<EOF
SELECT `tx_pages_rampfcompany_products_mm`.*, `pages`.*
FROM `tx_pages_rampfcompany_products_mm` `tx_pages_rampfcompany_products_mm`
         LEFT JOIN `pages` `pages` ON (`tx_pages_rampfcompany_products_mm`.`uid_foreign` = `pages`.`uid`) AND
                                      ((`pages`.`deleted` = 0) AND (`pages`.`hidden` = 0) AND
                                       (`pages`.`starttime` <= 1710315540) AND
                                       ((`pages`.`endtime` = 0) OR (`pages`.`endtime` > 1710315540)))
WHERE (`pages`.`doktype` = :dcValue1)
  AND (`tx_pages_rampfcompany_products_mm`.`uid_local` = :dcValue2)
  AND ((`pages`.`deleted` = 0) AND (`pages`.`t3ver_state` <= 0) AND (`pages`.`t3ver_wsid` = 0) AND
       ((`pages`.`t3ver_oid` = 0) OR (`pages`.`t3ver_state` = 4)) AND (`pages`.`hidden` = 0) AND
       (`pages`.`starttime` <= 1710315540) AND ((`pages`.`endtime` = 0) OR (`pages`.`endtime` > 1710315540)) AND
       (((`pages`.`fe_group` = '') OR (`pages`.`fe_group` IS NULL) OR (`pages`.`fe_group` = '0') OR
         (FIND_IN_SET('0', `pages`.`fe_group`)) OR (FIND_IN_SET('-1', `pages`.`fe_group`)))))
ORDER BY `tx_pages_rampfcompany_products_mm`.`sorting` ASC
EOF
            ,
            'expectedResult' => SqlParseResult::fromStrings(
                'pages',
                [
                    'pages.uid',
                    'pages.deleted',
                    'pages.hidden',
                    'pages.starttime',
                    'pages.endtime',
                    'pages.doktype',
                    'pages.t3ver_state',
                    'pages.t3ver_wsid',
                    'pages.t3ver_oid',
                    'pages.fe_group',
                    'tx_pages_rampfcompany_products_mm.uid_foreign',
                    'tx_pages_rampfcompany_products_mm.uid_local',
                    'tx_pages_rampfcompany_products_mm.sorting',
                ],
                true
            ), // if relation true keep fields empty??
        ];
        yield 'complex relational select' => [
            'sql' => "SELECT `r`.*, FIELD(`r`.`uid`, 152,153,154,155,5954) AS `order_uids` FROM `tx_rampfproduct_domain_model_product` `r` WHERE (`r`.`uid` IN (:uids)) AND ((`r`.`deleted` = 0) AND ((`r`.`endtime` = 0) OR (`r`.`endtime` > 1710315540))) ORDER BY `order_uids` ASC",
            'expectedResult' => SqlParseResult::fromStrings(
                'tx_rampfproduct_domain_model_product',
                [
                    'tx_rampfproduct_domain_model_product.uid',
                    'tx_rampfproduct_domain_model_product.deleted',
                    'tx_rampfproduct_domain_model_product.endtime',
                ],
                true
            ),
        ];
        yield 'FIND_IN_SET in ORDER BY unescaped' => [
            'sql' => "SELECT * FROM `tx_ausproject_domain_model_tile` WHERE (FIND_IN_SET(uid,'1,2,3,4')) AND (`tx_ausproject_domain_model_tile`.`deleted` = 0) ORDER BY FIELD(uid,1,2,3,4)",
            'expectedResult' => SqlParseResult::fromStrings(
                'tx_ausproject_domain_model_tile',
                [
                    'tx_ausproject_domain_model_tile.uid',
                    'tx_ausproject_domain_model_tile.deleted',
                ],
                true
            ),
        ];
        yield 'static_countries' => [
            'sql' => "SELECT `static_countries`.* FROM `static_countries` `static_countries` WHERE `static_countries`.`deleted` = 0",
            'expectedResult' => SqlParseResult::fromStrings(
                'static_countries',
                [
                    'static_countries.deleted',
                ],
                false
            ),
        ];
        yield 'static_country_zones zn_country_uid' => [
            'sql' => "SELECT `static_country_zones`.* FROM `static_country_zones` `static_country_zones` WHERE ((`static_country_zones`.`zn_country_uid` = :dcValue1) AND (`static_country_zones`.`zn_country_table` = :dcValue2)) AND (`static_country_zones`.`deleted` = 0) ORDER BY `static_country_zones`.`zn_name_local` ASC",
            'expectedResult' => SqlParseResult::fromStrings(
                'static_country_zones',
                [
                    'static_country_zones.zn_country_uid',
                    'static_country_zones.zn_country_table',
                    'static_country_zones.deleted',
                    'static_country_zones.zn_name_local',
                ],
                false
            ),
        ];
        yield 'static_country_zones zn_country_iso_nr' => [
            'sql' => "SELECT `static_country_zones`.* FROM `static_country_zones` `static_country_zones` WHERE (`static_country_zones`.`zn_country_iso_nr` = :dcValue1) AND (`static_country_zones`.`deleted` = 0)",
            'expectedResult' => SqlParseResult::fromStrings(
                'static_country_zones',
                [
                    'static_country_zones.zn_country_iso_nr',
                    'static_country_zones.deleted',
                ],
                false
            ),
        ];
        yield 'multiple Functions' => [
            'sql' => "SELECT * FROM `pages` WHERE TIME_TO_SEC(TIMEDIFF(NOW(), `tstamp`)) < 60",
            'expectedResult' => SqlParseResult::fromStrings(
                'pages',
                [
                    'pages.tstamp',
                ],
                false
            ),
        ];
        yield 'mainTable pages' => [
            'sql' => "SELECT p.*
FROM `pages` p
         JOIN tt_content t ON p.uid = t.pid
WHERE UPPER(LOWER(p.title)) != UPPER(LOWER(t.bodytext))
GROUP BY p.cruser_id
HAVING COUNT(p.abstract) > 1
ORDER BY t.tstamp DESC ",
            'expectedResult' => SqlParseResult::fromStrings(
                'pages',
                [
                    'pages.uid',
                    'tt_content.pid',
                    'pages.title',
                    'tt_content.bodytext',
                    'pages.cruser_id',
                    'pages.abstract',
                    'tt_content.tstamp',
                ],
                true
            ),
        ];
        yield 'mainTable tt_content' => [
            'sql' => "SELECT t.*
FROM `pages` p
         JOIN tt_content t ON p.uid = t.pid
WHERE UPPER(LOWER(p.title)) != UPPER(LOWER(t.bodytext))
GROUP BY p.cruser_id
HAVING COUNT(p.abstract) > 1
ORDER BY t.tstamp DESC ",
            'expectedResult' => SqlParseResult::fromStrings(
                'tt_content',
                [
                    'pages.uid',
                    'tt_content.pid',
                    'pages.title',
                    'tt_content.bodytext',
                    'pages.cruser_id',
                    'pages.abstract',
                    'tt_content.tstamp',
                ],
                true
            ),
        ];
        yield 'mainTable t.uid' => [
            'sql' => "SELECT p.title, t.uid FROM `pages` p JOIN tt_content t ON p.uid = t.pid",
            'expectedResult' => SqlParseResult::fromStrings(
                'tt_content',
                [
                    'pages.uid',
                    'tt_content.pid',
                ],
                true
            ),
        ];
        yield 'mainTable t.*' => [
            'sql' => "SELECT p.title, t.* FROM `pages` p JOIN tt_content t ON p.uid = t.pid",
            'expectedResult' => SqlParseResult::fromStrings(
                'tt_content',
                [
                    'pages.uid',
                    'tt_content.pid',
                ],
                true
            ),
        ];
        yield 'mainTable p.uid' => [
            'sql' => "SELECT p.uid FROM `pages` p JOIN tt_content t ON p.uid = t.pid",
            'expectedResult' => SqlParseResult::fromStrings(
                'pages',
                [
                    'pages.uid',
                    'tt_content.pid',
                ],
                true
            ),
        ];
        yield 'mainTable p.*' => [
            'sql' => "SELECT t.pid, p.*, t.CType FROM `pages` p JOIN tt_content t ON p.uid = t.pid",
            'expectedResult' => SqlParseResult::fromStrings(
                'pages',
                [
                    'pages.uid',
                    'tt_content.pid',
                ],
                true
            ),
        ];
        yield 'cache_rootline' => [
            'sql' => "SELECT `content` FROM `cache_rootline` WHERE (`identifier` = :dcValue1) AND (`expires` >= :dcValue2)",
            'expectedResult' => null,
        ];
        yield 'be_sessions' => [
            'sql' => "SELECT * FROM `be_sessions` WHERE `ses_id` = :dcValue1",
            'expectedResult' => null,
        ];
        yield 'sys_file_processedfile' => [
            'sql' => "SELECT * FROM `sys_file_processedfile` WHERE (`original` = :dcValue1) AND (`task_type` = :dcValue2) AND (`configurationsha1` = :dcValue3)",
            'expectedResult' => null,
        ];
        yield 'sys_note' => [
            'sql' => <<<EOF
SELECT `sys_note`.*,
       `be_users`.`username` AS `authorUsername`,
       `be_users`.`realName` AS `authorRealName`,
       `be_users`.`disable`  AS `authorDisabled`,
       `be_users`.`deleted`  AS `authorDeleted`
FROM `sys_note`
         LEFT JOIN `be_users` `be_users` ON `sys_note`.`cruser` = `be_users`.`uid`
WHERE (`sys_note`.`deleted` = :dcValue1)
  AND (`sys_note`.`pid` IN (:dcValue2))
  AND ((`sys_note`.`personal` = :dcValue3) OR (`sys_note`.`cruser` = :dcValue4))
  AND (`sys_note`.`position` = :dcValue5)
ORDER BY `sorting` asc, `crdate` desc
EOF
            ,
            'expectedResult' => SqlParseResult::fromStrings(
                'sys_note',
                [
                    'sys_note.cruser',
                    'sys_note.deleted',
                    'sys_note.pid',
                    'be_users.uid',
                    'sys_note.personal',
                    'sys_note.position',
                    'sys_note.sorting',
                    'sys_note.crdate',
                ],
                true
            ),
        ];
        yield 'tx_rampfproduct_domain_model_product pages' => [
            'sql' => <<<EOF
SELECT `tx_rampfproduct_domain_model_product`.`uid`,
       `tx_rampfproduct_domain_model_product`.`pid`,
       `tx_rampfproduct_domain_model_product`.`title`,
       `tx_rampfproduct_domain_model_product`.`hidden`,
       `tx_rampfproduct_domain_model_product`.`starttime`,
       `tx_rampfproduct_domain_model_product`.`endtime`
FROM `tx_rampfproduct_domain_model_product`,
     `pages`
WHERE (tx_rampfproduct_domain_model_product.pid = 115 AND
       tx_rampfproduct_domain_model_product.sys_language_uid IN (-1, 0))
  AND (1 = 1)
  AND (`pages`.`uid` = `tx_rampfproduct_domain_model_product`.`pid`)
  AND (((`tx_rampfproduct_domain_model_product`.`deleted` = 0) AND (`pages`.`deleted` = 0)) AND
       ((`pages`.`t3ver_wsid` = 0) AND ((`pages`.`t3ver_oid` = 0) OR (`pages`.`t3ver_state` = 4))))
ORDER BY `tx_rampfproduct_domain_model_product`.`title` ASC
EOF
            ,
            'expectedResult' => SqlParseResult::fromStrings(
                'tx_rampfproduct_domain_model_product',
                [
                    'tx_rampfproduct_domain_model_product.pid',
                    'tx_rampfproduct_domain_model_product.sys_language_uid',
                    'pages.uid',
                    'tx_rampfproduct_domain_model_product.deleted',
                    'pages.deleted',
                    'pages.t3ver_wsid',
                    'pages.t3ver_oid',
                    'pages.t3ver_state',
                    'tx_rampfproduct_domain_model_product.title',
                ],
                true
            ),
        ];
    }
}
