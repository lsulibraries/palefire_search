# Palefire Search

The Index: the search page at `/v2/search`, in the Palefire theme.

- **Local sources** (Pages, Questions & Answers, Databases, Gear, News, Staff,
  E-Textbooks, Archive Records): direct Search API queries on the
  `bento_search` index, matching all words.
- **External sources** (Articles & Books, Research Guides): the existing Views,
  run for their data. Their sections load after the page.
- **Links only** (Scholarly Repository, Catalog).

`src/SearchSources.php` holds every source and mode. To swap a service (such as
Discovery to Alma), change its definition and add an adapter in
`src/ExternalSearch.php`. Source keys are in shared addresses, so keep them
stable.

## Install

The Palefire theme's `palefire/index-search` library must be deployed first.
`/v2*` already uses Palefire through the `palefire_for_v2_pages` theme rule.

```
drush en palefire_search
drush cr
```

## Addresses

`/v2/search?q=&mode=all|research|website|special&source=&refine=&page=`

The old field name, `search_api_fulltext_bento`, also works as the search.

## Search operators

`src/SearchTerms.php` reads `AND`, `OR`, `NOT`, `-word`, `"phrases"` and `(groups)`, in capitals only, with AND and NOT binding tighter than OR. Local sources get nested Search API keys, and Discovery gets the search written out in its own syntax. Research Guides reads operators as words, so it gets the wanted words only, and the page says so. Local phrases match all their words in any order until the index's phrase setting is on.
