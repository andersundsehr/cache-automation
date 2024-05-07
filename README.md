# Cache Automation (auto cache tags + starttime and endtime)

TYPO3 has a good cache system, that is capable of removing the right caches at the right time.
But it needs your help to do so.

usually you have to add Cache Tags to your code, to tell TYPO3 which tables were used in your code.

> With this extension this Process is automated.

## Installation

```bash
composer require andersundsehr/cache-automation
```

Currently only Compatible with TYPO3 11 LTS (doctrine dbal 2.13)

## How this works in detail

This extension hooks into the TYPO3 QueryBuilder.   
It manipulates the Query to get all the necessary information, to provide the best cache lifetime.
The `starttime` condition is removed in the SQL Query, so we can get the lowest `starttime` that is still in the future.
The `endtime` is also used for the cache lifetime.
It is capable of handling complex queries, with multiple joins.

It also hooks into the Result Object, to intercept the result and add the Cache Tags to the Page Cache.
The `DocrineResult` filters out the rows that have a starttime in the future, so the rows returned are the same as if the starttime condition was still in the query.

## conditional fields

A conditional field is a field that is used in a WHERE condition of any Query.  
It can be useful to add the field to the cache tag, so the cache is cleared if the field changes.  


### TODO:

- read only mode for Metrics (aus vector extraction in eine extra Extension)
  - VECTOR \AUS\AusProject\Utility\MetricsCollectorUtility::collect
  - VECTOR \AUS\AusProject\Command\MetricsCommand::execute
- add cache clear for file operations (replace file, delete file ...) sys_file_<uid>
- SubQueries: at least we could create the `<tablename>-<fieldname>` cache tags for the subQuery.
- Option for: disable `<tablename>-<fieldname>` handling
- Option for: disable remove of `StartTimeRestriction`
- add github actions
- add TYPO3 12 and 13 compatibility


## Limitations of that Process

- Only works with the `\TYPO3\CMS\Core\Database\Query\QueryBuilder` (not with `getConcreteQueryBuilder()` `\Doctrine\DBAL\Query\QueryBuilder`)
  - indirectly it works with Extbases `\TYPO3\CMS\Extbase\Persistence\Generic\Query` because that uses the `\TYPO3\CMS\Core\Database\Query\QueryBuilder`.
  - indirectly it works with `Doctrine\DBAL\Query\QueryBuilder` because that uses the `\TYPO3\CMS\Core\Database\Query\QueryBuilder`.
- it does not work if you let out the uid of the table. (eg. `SELECT COUNT(*) FROM ...`) (`Connection->count()` uses that.)
- it does not know if the row you get from the database is really necessary for the cache tag.
- it creates way more cache tags than the Core, that could lead to overflows in the Database.
- it does not work with other Caches than the Page Cache.
  - maybe this can be added in the future, if there is a need for it.


between the Normal TYPO3 Cache handling and the Clear all caches on every Change,  
it Tries to be as close as possible at the Point there it always clears enough caches, but as little as possible.

# with ♥️ from anders und sehr GmbH

> If something did not work 😮  
> or you appreciate this Extension 🥰 let us know.

> We are hiring https://www.andersundsehr.com/karriere/
