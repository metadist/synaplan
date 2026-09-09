# serper_search

Reference Synaplan plugin: a **web_search** plug adapter backed by the
[Serper](https://serper.dev/) Google Search API. It exists to demonstrate that a
third party can add a search provider without touching `backend/src`.

## Install

The plugin is discovered automatically from the plugins directory. To enable it:

1. Get an API key from [serper.dev](https://serper.dev/).
2. In **Operate → AI infrastructure → Web search**, enter the key for **Serper
   (Google)** and save it.
3. Select Serper as the active provider (or fallback). The next chat search uses
   it.

Its health shows as unavailable until a key is stored; with no key it returns an
empty result set and never breaks a search.

## What it maps

`q` (with an optional `site:` filter), `num` (result count), `gl` (country),
`hl` (language) and `tbs` (freshness → `qdr:d|w|m|y`). Responses map the
`organic[]` entries (`title`, `link`, `snippet`, `date`) onto the shared
web-search result shape.
