# Test suite

Two suites, two very different jobs.

**Unit** (`tests/Unit`) runs without WordPress or a database. Brain Monkey stands
in for the WordPress function layer, so the domain objects — pricing, entitlement
state, progress arithmetic, cache invalidation — are exercised in milliseconds.
If a test here starts needing more than a handful of stubs, it belongs in the
other suite.

**Integration** (`tests/Integration`) runs against real WordPress, real MySQL,
real Tutor LMS and real WooCommerce. Nothing is mocked. These tests are slower,
and that is the point: they are the ones that notice when Tutor changes
`do_enroll()` or WooCommerce reorders its order hooks.

## Running locally

```bash
composer install

# Unit suite — no setup needed.
composer run test:unit

# Integration suite — needs MySQL and the WordPress test library once.
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1:3306 latest
bash bin/install-test-plugins.sh latest latest
composer run test:integration
```

If Tutor LMS or WooCommerce is missing, the tests that need them skip with a
clear message rather than failing — but CI always installs both, so a green
local run with everything skipped is not a green build.

## Seeded data

`tests/Support/Seeder.php` builds worlds through real APIs: courses get real
Tutor topics and lessons, orders go through `wc_create_order()`, and lesson
completion uses Tutor's own `mark_lesson_complete()`. The one exception is
`Seeder::access()`, which writes entitlement rows directly — that is the only
way to fabricate a grant that expired yesterday or was refunded last month.

`tests/Support/Scenarios.php` assembles those primitives into named situations:

| Scenario | What it represents |
| --- | --- |
| `overlapping_bundles()` | One course sold in two bundles, both owned |
| `direct_purchase_plus_bundle()` | A course bought directly, later also bundled |
| `woocommerce_purchase()` | A paid bundle with a real pending order |
| `large_bundle()` | More courses than the inline batch threshold |
| `bundle_with_broken_courses()` | One course deleted, one unpublished |
| `partial_progress()` | One course done, one half done, one untouched |
| `required_and_optional()` | Required courses finished, optional one not |
| `from_fixture()` | Any shape described in `tests/fixtures/*.json` |

## Fixtures

`tests/fixtures/bundle-shapes.json` and `access-states.json` describe data and
its expected interpretation side by side. Both suites read them through data
providers, so adding a new bundle shape or entitlement state means adding a JSON
block — no new PHP.

## CI

`.github/workflows/tests.yml` runs the unit suite across PHP 8.1–8.4, then the
integration suite across a matrix that deliberately includes the awkward
combinations: the minimum supported WordPress, a build with WooCommerce absent
entirely, and a non-blocking WordPress nightly canary.

`.github/workflows/lint.yml` covers syntax across all four PHP versions,
WordPress coding standards, and repository sanity checks — PSR-4 mapping, direct
access guards, fixture validity, and a grep that fails the build if anything
starts referencing Tutor LMS Pro.
