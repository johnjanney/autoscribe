# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Missing before 1.0

- Automated coverage for the Settings screen's save path and the Test connection
  control.
- No end-to-end test drives the Action Scheduler queue itself; the queued run
  handler is tested by calling it directly.

## [0.8.0] - 2026-08-17

Closes the coverage gaps left by phase 7, and two defects found while writing
the tests for them.

### Added

- Grounding source URLs are now extracted from Anthropic, OpenAI, and Google
  responses and recorded on the run row, as section 7.1 requires. They were
  captured by the response object and then discarded: no adapter ever passed
  them, so the feature was nominal.
- Optional Sources list appended to grounded articles, controlled by a new
  per-prompt setting.
- Tests for the queued run path, retry backoff and the three-attempt cap,
  Preview, taxonomy application, grounding, and `uninstall.php`.
- Section 11's schedule integration test: three prompts on three schedule types
  across a month boundary and both America/Chicago daylight saving transitions,
  each asserting an exact UTC instant.

### Fixed

- `uninstall.php` left `autoscribe_pricing`, `autoscribe_global_budget_cents`,
  and `autoscribe_budget_notice_month` behind. Those options were added in
  phases 5 and 7 and never added to the uninstall.
- `uninstall.php` deleted every `_autoscribe_%` post meta key, which included
  the `_autoscribe_generated` flag section 6 adds to generated attachments so
  they can be found later, and the `_autoscribe_run_id` link section 10 adds to
  generated posts. Uninstall keeps that content deliberately, so destroying the
  only means of identifying it was self-defeating. The sweep is now an explicit
  key list, and a test asserts both halves.
- Prompt posts are now removed on uninstall. They were left behind as
  unreachable content of an unregistered post type.

### Changed

- `actions/checkout` bumped to v5, clearing the Node 20 deprecation warning.

## [0.7.0] - 2026-08-17

Section 9's admin interface, which section 11 never assigned to a phase.

### Added

- Tabbed prompt editor covering every setting in section 3.2, with a next-run
  readout and Run now and Preview controls.
- Run Log list table with filtering by prompt, status, and month, and a Retry
  action on failed runs.
- Settings screen: provider credentials, default model IDs, global spend cap,
  editable pricing table, notification address, run-log retention, and a system
  health panel.
- `Run::query()`, `Run::count()`, and `Run::prune()`.
- Section 10's global "force human review" override, which beats the per-prompt
  setting and an explicit WP-CLI status argument alike.
- Section 3.2's daily run-log retention job, defaulting to 90 days.
- Admin notice reporting how many generated drafts are awaiting review.

### Changed

- Every prompt field is now declared once, in `Prompt_Fields`, which both the
  render and the save pass read. A test asserts the round trip, so a field
  cannot be rendered without also being saved.
- The pricing table and global cap keep their own options rather than moving
  into `autoscribe_settings`. Section 3.2 asks for a single option but section
  8.1 contradicts it for keys, and both are read on paths that never load the
  rest of the settings.

### Fixed

- `phpcs.xml.dist` was scanning `build/`, reporting every class in the plugin as
  a duplicate of its own build output.

## [0.6.0] - 2026-08-17

### Added

- Continuous integration: PHPCS and PHPUnit on PHP 8.1, 8.2, and 8.3.
- `README.md`, including the third-party service disclosure naming every provider
  endpoint the plugin contacts and the data sent to each.
- `languages/autoscribe.pot` for translators.
- `bin/build.sh`, producing an installable zip containing the plugin and its
  production dependencies only.
- A test asserting that the plugin header version and the `VERSION` constant
  agree, so the two cannot drift.

### Changed

- The duplicate-topic test now provides its own API key rather than relying on
  the constants the development environment happens to define. It passed locally
  and would have failed in CI for an environmental reason rather than a real one.

## [0.5.0] - 2026-08-16

### Added

- Budget guard blocking a run before any provider call once the month-to-date
  total meets the prompt's cap, with the run row itself acting as the
  reservation so concurrent Action Scheduler workers cannot both pass the check.
- Topic deduplication rejecting a proposal that collides with existing content
  before the expensive body call, with a re-ask naming the collision.
- SEO adapters for Yoast, Rank Math, and SEOPress, writing to verified meta keys,
  and a null adapter for sites running none of them.

## [0.4.0] - 2026-08-16

### Added

- `Next_Run_Calculator` covering all six schedule types, including nth-weekday and
  last-weekday of month, month-end roll-forward, leap days, both DST transitions,
  and catch-up from a stored next run in the past.

## [0.3.0] - 2026-08-16

### Added

- End-to-end pipeline: topic proposal, body generation, image generation,
  sanitization, and post assembly, drivable from a single WP-CLI invocation.
- Featured image sideloading with alt text.

## [0.2.0] - 2026-08-16

### Added

- Provider adapters for Anthropic, OpenAI, Google, and DeepSeek text generation,
  and OpenAI and Google image generation.
- Test suite asserting the outgoing request shape for every adapter, and that a
  401 returns a `WP_Error` rather than a fatal.
- An HTTP tripwire that fails any test making an unmocked request.

## [0.1.0] - 2026-08-16

### Added

- Plugin scaffold, activation and uninstall routines, custom tables, the prompt
  post type, and encrypted API key storage.
