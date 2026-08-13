=== HealthPress ===
Contributors: ericbinnion
Tags: health, tracking, metrics, notes, wp-cli
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track personal health metrics — blood pressure, weight, sleep and more — with a
typed data model and a WP-CLI interface, alongside a searchable archive of notes.

== Description ==

HealthPress records health measurements the way a health platform does: a
code-defined catalog of metric types, each with typed fields, canonical units,
and sane bounds, accumulating readings over time.

It also keeps **notes** — transcripts of calls, notes from a doctor, lab
summaries — as a separate, searchable archive. Readings are numbers; notes are
documents. They share a timeline by date and nothing else.

= Recording a reading =

**HealthPress → Add Reading** offers one box: pick a metric, fill in its values,
add an optional note. The value fields change with the metric. The title is
generated, so there is nothing to name, and the measurement time is the Publish
box's own date control — `post_date` *is* the timestamp.

A reading that fails validation is **not saved**. It is left as a draft, the
reasons are listed above the form, and the values you submitted are handed back
so nothing has to be retyped. Drafts are skipped by every listing, so **Save Draft**
doubles as "hold this one back".

Editing a stored reading that already passed is safe: if the edit is refused,
the stored values are left exactly as they were.

= How data is stored =

Each reading is one `hp_reading` post:

* the metric is a term in the `hp_metric` taxonomy
* the timestamp is `post_date_gmt`
* each measured value is post meta, under a per-metric key such as
  `_hp_blood_pressure_systolic`
* the note, if any, is the post content

Both filter dimensions therefore land on indexed columns rather than on meta.

= Units =

Every field declares one canonical unit and readings are always stored in it —
kilograms, mmHg, degrees Celsius. Nothing converts them.

Earlier versions converted at the REST boundary, resolved by dimension, so a
request could ask for pounds. That boundary went with the REST API, and rather
than move the conversion somewhere it had no caller, it was removed: the CLI
prints canonical values and names the unit in its own column, so a number is
never ambiguous about what it is measured in.

= Notes =

**HealthPress → Notes** records documents rather than measurements: transcripts
of calls, notes from a doctor, lab summaries, anything worth keeping and finding
again. Notes are a searchable archive; they carry no numbers and no units.

Each note is one `hp_note` post: the body is the post content, the date is
`post_date_gmt` and means when the call or visit happened, and it is filed under
three taxonomies — one **kind**, a **provider**, and any number of **tags**.

The body is a plain textarea rather than the block editor, so a pasted
transcript stays flat text instead of being chopped into paragraph blocks. It is
not stored byte for byte, though: the text is sanitised, which removes anything
that parses as a tag and HTML-encodes a lone `<`, so `HbA1c <5.7%` is stored as
`HbA1c &lt;5.7%` and displays as it was typed. That is deliberate — no stored
note can carry markup even if something later renders it without escaping.

Kinds ship seeded (Transcript, Doctor's note, Lab summary, Personal log) and can
be edited freely, unlike metrics. A kind has no schema behind it, and nothing
resolves a kind slug in code — you pick one from the terms that exist — so
there is nothing for the plugin to keep in step.

= Importing a note =

The Note box takes a `.txt` or `.md` file. **The file is read in your browser
and never uploaded**: nothing reaches the server, nothing is added to the media
library, and the text lands in the box where you can trim it before saving. If
the box already has text, you are asked before it is replaced.

= Finding a note =

The search box on the notes list searches the body, because the body *is* the
post content. Alongside it are filters for kind, provider, tag, and a date
range, and each taxonomy column doubles as a filter link.

Search is a substring match rather than a ranked one — Studio's SQLite backend
has no full-text index — which is ample for a personal archive.

Notes are reachable from the command line too — see the WP-CLI section.

= Validation =

All rules live in a single validator, so the admin form, the command line, the
repository, and any future importer enforce exactly the same thing. Violations are
collected rather than reported one at a time, so a rejected write explains
everything that is wrong with it at once.

Unknown field keys are rejected rather than ignored, so a typo fails loudly
instead of silently recording nothing.

== WP-CLI ==

The plugin's programmatic surface. It requires the `manage_options` capability,
same as everything else here.

Read and create only. Editing and deleting stay in wp-admin, where what a record
sits next to is on screen — and every extra write path is another place the
validator could be bypassed.

= Readings =

    wp healthpress metric list
    wp healthpress reading list [--metric=<slug>] [--after=<date>] [--before=<date>]
                               [--limit=<n>] [--offset=<n>] [--order=<asc|desc>]
    wp healthpress reading get <id>
    wp healthpress reading add --metric=<slug> --<field>=<value>... [--date=<date>]
                               [--note=<text>] [--porcelain]

Values are given one flag per field and are always in the field's canonical unit
— `--value=72.5` for weight is kilograms. `wp healthpress metric list` shows
which fields and units each metric takes.

`reading add` goes through the same validator the admin form does, so a rejected
write explains everything wrong with it at once and records nothing. A field flag
that is not part of the metric is refused rather than ignored, so a typo fails
loudly.

    # Every weight reading ever recorded, as CSV.
    wp healthpress reading list --metric=weight --limit=0 --format=csv

`--limit=0` returns everything, paging internally. A single query is still capped
at 100, which guards against an unbounded query rather than limiting an export.

= Notes =

    wp healthpress note list [--kind=<slug>] [--provider=<slug>] [--tag=<slug>]
                             [--search=<text>] [--after=<date>] [--before=<date>]
                             [--limit=<n>]
    wp healthpress note get <id> [--body-only]
    wp healthpress note add --title=<title> [--body=<text>] [--body-file=<path>]
                            [--kind=<slug>] [--provider=<name>] [--tags=<list>]
                            [--date=<date>] [--porcelain]

    # Import a transcript from a file, the way the editor's import control does.
    wp healthpress note add --title="Cardiology call" --body-file=transcript.txt --kind=transcript

    # Pull one back out and pipe it.
    wp healthpress note get 1597 --body-only | grep -i "blood pressure"

A `--kind` must already exist, because kinds are a controlled vocabulary and an
unrecognised one is a typo rather than a new kind. Providers and tags are created
on demand, because those lists grow.

Every command takes `--format=table|csv|json|yaml|count|ids`, and the list
commands take `--fields=` to choose columns.

= Notes on Studio =

`studio wp` does not forward standard input, so piping a body in — `cat file | wp
healthpress note add` — does not work there. Use `--body-file` instead, and note
that the PHP runtime cannot read outside the site directory, so the file has to
live inside it.

== Extending ==

Add a metric with the `healthpress_metrics` filter. It runs on `init` at
priority 5, so register before then.

`healthpress_registry_ready` fires once the catalog is final, and
`healthpress_max_future_seconds` adjusts how much clock skew a future-dated
reading may carry.

== Frequently Asked Questions ==

= Why is there no REST API? =

There was one, and it was removed in 0.4.0 in favour of WP-CLI. For a single-user
plugin on a site you administer, a shell is the more useful surface: it composes
with `grep`, `jq` and a pipe, and it needs no authentication dance.

Core's own `/wp/v2/hp_reading` is off for the reason the plugin's routes were
careful in the first place — it writes straight to `wp_insert_post()` and never
reaches the validator. The admin screens and `reading add` both go through the
same repository method, so there is one place the rules live.

= Why can't I add or rename a metric in the Metrics screen? =

Metrics are defined in code, so a term added by hand would name a metric with no
schema behind it — no fields, no units, no bounds. Use the `healthpress_metrics`
filter instead. The screen stays available for reading.

= A reading says "Incomplete reading". What is it? =

A save that was refused. Open it to see why. Such a row is not treated as a
reading anywhere: it is skipped by the API and by every listing, whether it came
from a refused save or from an older version of this plugin.

= What happens if I remove a metric from the registry? =

Nothing is deleted. Its term and every reading attached to it are kept —
removing the term would silently detach the history. Those readings report as
orphaned when read, and start working again the moment the metric is registered
back.

= I dated a note in the future and it vanished from the list. Where is it? =

WordPress schedules any post dated in the future, so the note is there but its
status is Scheduled — use the Scheduled link above the list. Notes deliberately
leave this alone. A reading refuses a future date outright, because a
measurement cannot have been taken yet; a note is just a document, and the date
is yours to set.

= Why is my note's `<` showing as `&lt;` in the database? =

Because the body is sanitised on save, which encodes a lone `<` and strips
anything that looks like a tag. It displays correctly wherever the plugin shows
it. The trade buys a guarantee: no note can hold markup, so nothing can render
one as HTML by accident.

= Does uninstalling delete my data? =

No. Uninstall removes only this plugin's own options and leftover transients.
Every reading and every note is left in place, along with their terms — health
data should not evaporate because someone fumbled a deactivate.

There is no supported way to make it delete them. Earlier versions of this file
described a `healthpress_delete_data_on_uninstall` option, but nothing has ever
read it, so setting it does nothing. To remove the data, delete the readings and
notes from their list screens before uninstalling.

== Changelog ==

= 0.4.0 =
* Removed the REST API. `healthpress/v1` and both its controllers are gone.
* Added WP-CLI commands in their place: `wp healthpress metric list`, and
  `list`, `get` and `add` for both readings and notes. Read and create only —
  editing and deleting stay in wp-admin.
* `reading add` goes through the same validator the admin form does, so the rules
  live in one place. An unrecognised field flag is refused rather than ignored.
* `note add` takes its body inline, from a file, or on standard input, and
  sanitises it through the same code path the editor's metabox uses.
* Readings are no longer unit-converted anywhere. Values were always stored
  canonically and only converted at the REST boundary; with that boundary gone
  the conversion had no caller, so it was removed rather than relocated. Output
  names the unit alongside the number.
* `reading list --limit=0` returns everything, paging internally rather than
  lifting the per-query cap that guards against unbounded queries.
* Added `cli` as a reading source. `api` is kept so readings written by the old
  REST API still validate when read back.

= 0.3.0 =
* Added Notes: a searchable archive of documents — transcripts of calls, notes
  from a doctor, lab summaries — filed by kind, provider, tag and date. Notes are
  independent of readings; they share a timeline by date and nothing else.
* A note's body is a plain textarea rather than the block editor, so a pasted
  transcript stays flat text instead of being chopped into paragraph blocks. It
  is sanitised on the way in, which encodes a lone `<` and strips anything
  parsing as a tag, so no stored note can carry markup.
* Text and markdown files can be imported into a note. The file is read in the
  browser and never uploaded, so nothing reaches the server and nothing is added
  to the media library.
* Notes are searchable by body text from the list screen, and filterable by
  kind, provider, tag and date range. Each taxonomy column doubles as a filter
  link.
* Note kinds ship seeded and stay editable, unlike metric terms: a kind carries
  no schema, and nothing resolves a kind slug in code.
* Notes have no REST API yet.

= 0.2.0 =
* Added an entry form to the reading screen: a metric selector, per-metric value
  fields, and a note. Title, editor, and custom fields are gone — none of them
  could record a reading, and the custom fields box never could, because value
  keys are protected meta.
* Fixed: publishing an empty reading from the admin screen created a row with a
  metric and no values, which then appeared in the REST API. The admin screen now
  writes through the same validator the API does, and such a row is refused on
  read — retroactively, so an affected database is fixed by upgrading.
* Metric terms can no longer be created, renamed, or assigned by hand.
* A reading's metric can now be changed; the previous metric's values are removed.
* Fixed: empty `values` and `units` serialised as `[]` rather than `{}`.
* Fixed: a titleless reading given a future date was written as `future` rather
  than `publish`, so the guard against publishing one never saw it, and cron
  published it later through a path that consults no filter.
* Removed post meta registration. Every key was `show_in_rest => false` on a
  post type with no editor support, so nothing consulted it, and its
  sanitisation duplicated the formatting the repository already applies.
* Simplified metric syncing. The structural hash that gated it deliberately
  excluded labels and descriptions — the only two things a term carries — so it
  was gating on something that could never require a term to change. Syncing is
  now gated on the plugin version, and the slug-to-term map it maintained
  alongside the taxonomy's own index is gone. Both leftover options are removed
  on upgrade.

= 0.1.0 =
* Initial release: metric registry, reading storage, and the REST API.
