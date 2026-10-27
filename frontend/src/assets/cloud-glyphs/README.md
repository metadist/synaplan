# Cloud glyphs

The bird marks a file the assistant can search. Drawn on a 24 px grid with a
1.5 px stroke and `currentColor`, in the same outline style as Heroicons.
The apps copy these shapes. Do not recolour them: the surrounding text colour
is the contrast.

| Glyph          | Meaning                               |
| -------------- | ------------------------------------- |
| `bird`         | Searchable                            |
| `bird-eyes`    | Watched                               |
| `badge-clock`  | Being added                           |
| `badge-arrows` | Changed, and kept up to date          |
| `badge-dot`    | Changed                               |
| `badge-alert`  | Could not, or a watch needs attention |
| `badge-pause`  | Watching is paused                    |

Sizes in the product are 16 px and 20 px. `currentColor` inherits the text
colour, so contrast is the contrast of that text. On the Library row that is
emerald 600 (`#059669`) on white, about 3.8:1, and emerald 400 (`#34d399`) on
the dark card (`#1c1c1e`), about 8:1. Both clear the 3:1 icon threshold.

A white-label install can replace the two base glyphs. When cloud file state
is on, runtime config may carry `cloud_files.glyphs.bird` and
`cloud_files.glyphs.bird_eyes` as URLs. An empty value keeps the bundled shape.
