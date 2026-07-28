=== Tutor Course Bundles ===
Contributors: spacework
Tags: tutor lms, lms, course bundle, elearning, woocommerce
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Group multiple Tutor LMS courses into one purchasable bundle, with a proper access ledger, WooCommerce support and aggregated progress.

== Description ==

Tutor Course Bundles lets you sell several Tutor LMS courses as a single product. A learner buys once and is enrolled in every course in the bundle.

It is built entirely on the public APIs of Tutor LMS (free), WordPress and WooCommerce. It does not call into, unlock or emulate any Tutor LMS Pro code.

**What it does**

* Bundles are a custom post type, so you get Gutenberg, revisions, SEO plugins and permalinks for free.
* Pick courses with an AJAX search, drag to reorder, and mark individual courses required or optional.
* Sell through WooCommerce as a simple virtual product that is created and kept in sync automatically, or give bundles away for free.
* Learners are enrolled in every course when an order is paid, and the process is idempotent — repeated webhooks never create duplicate enrollments.
* A "My Bundles" tab in the Tutor dashboard shows aggregated progress and a Continue Learning link.
* Full REST API under `tcb/v1`, plus five shortcodes and a template system your theme can override.

**The access ledger**

The heart of the plugin is not the course list — it is the record of *why* each learner has access to each course.

Every course enrollment created by a bundle is attributed to a specific entitlement. When a bundle is refunded, expires, or has a course removed, the plugin checks whether the learner still holds that course through another route: a second bundle, a direct purchase, a manual grant. Only when nothing else covers it is the enrollment cancelled.

Without this, refunding one bundle would quietly strip courses a learner paid for separately. With it, that cannot happen.

== Installation ==

1. Install and activate Tutor LMS.
2. Upload the plugin to `/wp-content/plugins/tutor-course-bundles` and activate it.
3. Go to Tutor LMS → Course Bundles → Add New.
4. Visit Settings → Permalinks once to flush rewrite rules.

WooCommerce is only required if you want to sell paid bundles.

== Shortcodes ==

* `[tcb_bundle id="123"]` — bundle card with pricing and call to action
* `[tcb_bundle_courses id="123"]` — the course list
* `[tcb_bundle_progress id="123"]` — progress bar for the current learner
* `[tcb_my_bundles]` — the learner's own bundles
* `[tcb_bundle_grid count="12" columns="3" category="design"]` — catalogue grid

== Template overrides ==

Copy any file from `templates/` into `yourtheme/tutor-course-bundles/` and edit it there:

* `single-bundle.php`
* `archive-bundle.php`
* `bundle-card.php`
* `bundle-courses.php`
* `bundle-progress.php`
* `dashboard/bundles.php`
* `dashboard/bundle-details.php`

== Hooks for developers ==

Actions:

* `tcb/bundle/created`, `tcb/bundle/updated`, `tcb/bundle/deleted`
* `tcb/access/granted`, `tcb/access/revoked`
* `tcb/course/enrolled`
* `tcb/bundle/completed`

Filters:

* `tcb/bundle/courses`
* `tcb/bundle/price`
* `tcb/bundle/progress`
* `tcb/bundle/can_enroll`
* `tcb/bundle/template_path`
* `tcb/access/has_alternative`
* `tcb/commerce/gateways`
* `tcb/enrollment/batch_threshold`

== Frequently Asked Questions ==

= Does this require Tutor LMS Pro? =

No. It uses only the free plugin's public API. It also never checks for or unlocks Pro features.

= What happens to a learner's courses when I refund a bundle? =

Only the courses they hold *solely* through that bundle are removed. Anything they bought separately, received from another bundle, or were granted manually is untouched.

= What if I add a course to a bundle after people have bought it? =

By default existing learners get it too — they bought the bundle, not a fixed list. You can change this per bundle.

= Can I bundle a bundle? =

Not in 1.0.0. Nesting creates circular dependencies, ambiguous pricing and double-counted progress; it is deliberately out of scope.

= What about large bundles? =

Bundles over 20 courses are enrolled in the background via Action Scheduler (WooCommerce) or WP-Cron, so checkout never times out. Failed rows are visible and retryable from Tutor LMS → Bundle Enrollments.

== Changelog ==

= 1.0.0 =
* Initial release: bundle post type, course membership table, access ledger, free and WooCommerce enrollment, refund and expiry handling, student dashboard, aggregated progress, REST API, reports and CSV export.

== Upgrade Notice ==

= 1.0.0 =
First release.
