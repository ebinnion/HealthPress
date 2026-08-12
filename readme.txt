=== HealthPress ===
Contributors: ericbinnion
Tags: health, tracking, metrics, notes, rest-api
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track personal health metrics — blood pressure, weight, sleep and more — with a
typed data model and a REST API, alongside a searchable archive of notes.

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
so nothing has to be retyped. Drafts are invisible to the REST API, so **Save
Draft** doubles as "hold this one back".

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

Every field declares one canonical unit and readings are always stored in it.
Conversion happens only at the REST boundary, resolved by *dimension*: a request
for `?unit=lb,f` converts every mass field and every temperature field, and
leaves unitless fields — such as a sleep quality score — untouched. Responses
always report which unit their numbers are in.

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

Notes have no REST API yet. They are an admin-only feature for now.

= Validation =

All rules live in a single validator, so REST, the repository, and any future
importer enforce exactly the same thing. Violations are collected rather than
reported one at a time, so a rejected write explains everything that is wrong
with it at once.

Unknown field keys are rejected rather than ignored, so a typo fails loudly
instead of silently recording nothing.

== REST API ==

All routes require the `manage_options` capability.

* `GET    /wp-json/healthpress/v1/metrics`
* `GET    /wp-json/healthpress/v1/metrics/<slug>`
* `GET    /wp-json/healthpress/v1/readings`
* `POST   /wp-json/healthpress/v1/readings`
* `GET    /wp-json/healthpress/v1/readings/latest?metric=<slug>`
* `GET    /wp-json/healthpress/v1/readings/<id>`
* `PUT`, `PATCH`, `DELETE` `/wp-json/healthpress/v1/readings/<id>`

Collection parameters: `metric`, `after`, `before`, `per_page`, `page`, `order`,
`unit`.

== Extending ==

Add a metric with the `healthpress_metrics` filter. It runs on `init` at
priority 5, so register before then.

`healthpress_registry_ready` fires once the catalog is final, and
`healthpress_max_future_seconds` adjusts how much clock skew a future-dated
reading may carry.

== Frequently Asked Questions ==

= Why are readings not on /wp/v2? =

Exposing the post type there would create a second write path, straight to
`wp_insert_post()`, that never reaches the validator. One write path means one
place rules can be enforced. The admin screen goes through the same repository
method the REST API does, for the same reason.

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
