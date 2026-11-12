# Palefire Search

Combined Search (working name "the Index"): the search page at `/v2/search`, in the Palefire theme.

- **Local sources** (Pages, Questions & Answers, Databases, Gear, News, Staff,
  E-Textbooks, PDF Finding Aids, Oral histories): direct Search API queries on
  the `bento_search` index, matching all words. PDF Finding Aids searches
  finding aids and Oral histories the oral history abstracts, each an archive
  record type (`record_field`/`record_types` in `SearchSources`). Oral
  histories has a Series list under it listing every series, those with no
  match disabled. Old addresses (`refine[]=archive:oral_history`,
  `archive:series:…`) still work: they're read as the Oral histories source.
- **External sources** (Discovery, Catalog, Journals, Research Guides, Scholarly
  Repository): each from its own service. Their sections load after the page.

## Scholarly Repository

The repository's search turns away requests from this server with a firewall
challenge, but its OAI-PMH feed (`https://repository.lsu.edu/do/oai/`) answers.
So `RepositoryHarvest` copies every record (qualified Dublin Core: title,
authors, abstract, keywords, type, date, series, page address) into the
`palefire_search_repository` table, and `RepositorySearch` searches it: all
words required, titles weighted, with MySQL full-text indexes. Rows show the
part of the abstract where the words were found. The feed has no text from the
files, so the source's note and its Go to link send people to the repository's
own search for full-text matches.

The table comes from `drush updb` (`palefire_search_update_11001`). Fill it once
with drush, which takes about half an hour (82,000 records, 100 a page):

```
drush palefire-search:repository-harvest
```

After that, cron reads a few pages a run: what changed, daily, and everything
monthly, dropping records the repository no longer lists (the feed doesn't
report deletions). A run saves its place after each page, so an interrupted one
carries on next time. `--status` says how many records are held, `--full`
starts a full run now, and `--pages=N` stops after N pages.

Changes, 2026-11-12: the Scholarly Repository was a link only after its search
began refusing this server. It's a section again in All and Research, from the
harvested copy, with a Year published filter and date sorting.

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

Discovery's filters: Limit to (full text, peer reviewed, in the Libraries'
collection, EPUB e-books) are the service's limiters, and each narrows. Type and
Language go in one facet filter, so values of one widen and the two together
narrow. Language uses the facet, not the `LA99` limiter, which reads only some
databases and found 13 French results where the facet counts 65. A language
filter takes every name the facet uses for it (`spanish` and `spanish;
castilian`). Once a language is chosen, the service counts the others within it,
so the languages show no numbers then. The API profile doesn't search the LSU
catalog database, so Discovery's own site shows catalog books this list doesn't:
they're in the Catalog section.

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

In the `bento_search` index, turn on the processor **Archive records: finding aids and oral histories only** (Processors tab), then reindex. It leaves out the archive records the search never shows, such as Breaux Data, which were most of the index. It keeps every record type a source searches (finding aids and oral histories). Oral histories filter by series once `field_series` is in the index as a String.

## Oral history series

`src/OralHistorySeries.php` maps each abstract folder (`/sites/all/files/oralhistory/abstracts/<folder>/`) to the series name the site shows. The folder is what the Catalog and the site share: the Catalog's own series wording is uneven or missing. `scripts/import_oral_histories.php` adds an archive record for every abstract the Catalog links in a mapped folder that the site has no record for (title, PDF, series, and the Catalog's summary, interviewee and interviewer). It never changes existing records. From the site's web folder:

```
drush php:script modules/custom/palefire_search/scripts/import_oral_histories.php
drush php:script modules/custom/palefire_search/scripts/import_oral_histories.php -- apply=1
drush search-api:index bento_search
```

The first is a dry run. `folder=<name>` limits it to one series. To add a series, add its folder and name to the map first.

To rename a series, change its name in the map, then run `scripts/sync_oral_history_series.php` the same way (dry run, then `-- apply=1`, then reindex). It sets each oral history record's Series from its abstract's folder, and fixes records without an abstract whose series is the same name written another way.

## Feedback

Under the results the page asks "Did you find what you needed?". Each answer links to the Webform `combined_search_feedback` (at `/v2/search/feedback`) with the search filled in. A Feedback link beside Search tips opens the same form from the top of the page, with no answer chosen, before a search too. The question and the link show only while that Webform exists and is open. `scripts/create_feedback_webform.php` makes it. From the site's web folder:

```
drush php:script modules/custom/palefire_search/scripts/create_feedback_webform.php
drush php:script modules/custom/palefire_search/scripts/create_feedback_webform.php -- apply=1 email=<group address>
```

The first is a dry run. `email=` is optional. An existing form is left alone unless `replace=1`. Its hidden fields (`search_terms`, `search_mode`, `result_count`, `sources_shown`, `filters_chosen`, `screen`) and `found` and `looking_for` take their values from the address, so their keys must match the page's.

The theme opens the form in a drawer over the results: it fetches this page and posts the form itself, so the Webform keeps Ajax off (no jQuery). It also has an optional screenshot field (one PNG, JPG or WebP image, up to 5 MB), which is added only when the site has a private file directory. Responses are deleted after 365 days (`purge_days=N` changes that, `purge_days=0` keeps them). Honeypot, if enabled, runs without its time limit, since Yes followed quickly by Send is a real answer.

## Addresses

`/v2/search?q=&mode=all|research|website|special&source=&refine=&page=`

The old field name, `search_api_fulltext_bento`, also works as the search.

## Search operators

`src/SearchTerms.php` reads `AND`, `OR`, `NOT`, `-word`, `"phrases"` and `(groups)`, in capitals only, with AND and NOT binding tighter than OR. Local sources get nested Search API keys, and Discovery gets the search written out in its own syntax. Research Guides reads operators as words, so it gets the wanted words only, and the page says so. Local phrases match all their words in any order until the index's phrase setting is on.
