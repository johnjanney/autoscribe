# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Missing before 1.0

- The admin interface described in section 9 of the project brief: settings page,
  run-now button, run log list table, pricing table editor. Prompts are currently
  managed through the standard post-type screens and WP-CLI.
- Automated coverage for grounding and taxonomy application.
- Runtime coverage for `uninstall.php`, which is currently only syntax-checked.

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
