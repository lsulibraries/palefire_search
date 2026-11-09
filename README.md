# Palefire Search

The Index: the search page at `/v2/search`, in the Palefire theme.

- **Local sources** (Pages, Questions & Answers, Databases, Gear, News, Staff,
  E-Textbooks, PDF Finding Aids): direct Search API queries on the
  `bento_search` index, matching all words. PDF Finding Aids searches only
  finding aids and oral history abstracts, with Oral histories as a filter
  under it.
- **External sources** (Discovery, Catalog, Research Guides): each from its own
  service. Their sections load after the page.
- **Links only** (Scholarly Repository).

The Catalog uses two public services together. Its results feed chooses and
orders the rows, because only the feed ranks by relevance. Its web service then
adds each row's copies and availability, from `/v2/search/holdings`, which loads
after the rows and is kept for two minutes. Copies link to the shelf map, a hold
on the catalog's record page, or the Special Collections request form. If the feed goes down, the web service
answers the search too, newest first and saying so. It has no working Type or Year
filter, so a search using one shows the failure message instead.

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
