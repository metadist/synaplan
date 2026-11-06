# Synafastbill

Draft invoices, register payments, and list overdue invoices from the
Synaplan chat with `/fastbill`. A write is sent only after the user confirms.

This directory is the plugin that ships in the Synaplan image. It was taken
on 2026-11-06 from the copy already running beside this checkout. That copy
had been formatted for this repository and no longer matched
[metadist/synafastbill](https://github.com/metadist/synafastbill)
`42323c283ee764b677d0342aba13b4fe002d77da` line for line. That commit is still
the GitHub tip: the clone is clean, even with `origin/main`, and there is no
open pull request. History was not imported.

Outbound FastBill calls go through Synaplan's shared HTTP client.
