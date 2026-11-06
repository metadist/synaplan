# Synaform

Forms, templates, and document generation. The page and the API live here
and ship in the Synaplan image.

This directory was taken on 2026-11-06 from the copy already running beside
this checkout (manifest 4.4.3). That copy uses `AiFacade` and this
repository's formatting, so it is not a byte match of
[metadist/Synaform](https://github.com/metadist/Synaform)
`d98ae8d4ea7cdacc6aa0c889229579e2ef2bdcaa`. That commit is still the GitHub
tip: the clone is clean, even with `origin/main`, and there is no open pull
request. History was not imported.

URL fetches go through Synaplan's shared HTTP client. The controller and the
page are still larger than this repository's size bar; splitting them is
later work, and PHPStan still skips this plugin until that split.
