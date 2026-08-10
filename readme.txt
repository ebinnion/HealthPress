=== HealthPress ===
Contributors: ericbinnion
Tags: health, tracking, metrics, rest-api
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track personal health metrics — blood pressure, weight, sleep and more — with a
typed data model and a REST API.

== Description ==

HealthPress records health measurements the way a health platform does: a
code-defined catalog of metric types, each with typed fields, canonical units,
and sane bounds, accumulating readings over time.

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

= Does uninstalling delete my data? =

No. Uninstall removes only this plugin's options. To also remove readings, set
the `healthpress_delete_data_on_uninstall` option to a truthy value first.

== Changelog ==

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
