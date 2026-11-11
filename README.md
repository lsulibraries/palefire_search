# Palefire Search

Combined Search (working name "the Index"): the search page at `/v2/search`, in the Palefire theme.

- **Local sources** (Pages, Questions & Answers, Databases, Gear, News, Staff,
  E-Textbooks, PDF Finding Aids): direct Search API queries on the
  `bento_search` index, matching all words. PDF Finding Aids searches only
  finding aids and oral history abstracts, with Oral histories as a filter
  under it.
- **External sources** (Discovery, Catalog, Journals, Research Guides): each from
  its own service. Their sections load after the page.
- **Links only** (Scholarly Repository).

Journals (in Research) is the homepage's e-journal search: the titles in the
Libraries' online holdings, from Discovery's own service and proxy
(`/edsapi/publication/search`), limited to journals, newspapers, newsletters and
reports. Each row lists up to three packages, best first (running to today,
reaching furthest back, no delay), with their years and any delay, and links to
the service's page for the rest. The service drops its Type counts once a type
is chosen, so the Type filter has no numbers. Paging stops at record 251.

Discovery also asks for the journal a search names (`relatedcontent=emp`, first
page only) and shows it in a card above its rows, with the same coverage lines.
The service offers near matches too, so the card appears only when the title is
the search itself, case, punctuation and a leading "The" aside
(`JournalSearch::sameTitle()`).

The Catalog uses two public services together. Its results feed chooses and
orders the rows, because only the feed ranks by relevance. Its web service then
adds each row's copies and availability, from `/v2/search/holdings`, which loads
after the rows and is kept for two minutes. Copies link to the shelf map, the
catalog's hold page (its sign-in, then the hold form), or the Special Collections request form. If the feed goes down, the web service
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

In the `bento_search` index, turn on the processor **Archive records: finding aids and oral histories only** (Processors tab), then reindex. It leaves out the archive records the search never shows, such as Breaux Data, which were most of the index. Oral histories filter by series once `field_series` is in the index as a String.

## Oral history series

`src/OralHistorySeries.php` maps each abstract folder (`/sites/all/files/oralhistory/abstracts/<folder>/`) to the series name the site shows. The folder is what the Catalog and the site share: the Catalog's own series wording is uneven or missing. `scripts/import_oral_histories.php` adds an archive record for every abstract the Catalog links in a mapped folder that the site has no record for (title, PDF, series, and the Catalog's summary, interviewee and interviewer). It never changes existing records. From the site's web folder:

```
drush php:script modules/custom/palefire_search/scripts/import_oral_histories.php
drush php:script modules/custom/palefire_search/scripts/import_oral_histories.php -- apply=1
drush search-api:index bento_search
```

The first is a dry run. `folder=<name>` limits it to one series. To add a series, add its folder and name to the map first.

To rename a series, change its name in the map, then run `scripts/sync_oral_history_series.php` the same way (dry run, then `-- apply=1`, then reindex). It sets each oral history record's Series from its abstract's folder, and fixes records without an abstract whose series is the same name written another way.

## Addresses

`/v2/search?q=&mode=all|research|website|special&source=&refine=&page=`

The old field name, `search_api_fulltext_bento`, also works as the search.

## Search operators

`src/SearchTerms.php` reads `AND`, `OR`, `NOT`, `-word`, `"phrases"` and `(groups)`, in capitals only, with AND and NOT binding tighter than OR. Local sources get nested Search API keys, and Discovery gets the search written out in its own syntax. Research Guides reads operators as words, so it gets the wanted words only, and the page says so. Local phrases match all their words in any order until the index's phrase setting is on.
