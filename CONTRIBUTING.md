# Contributing

## Setup

```bash
composer install
npx wp-env start
```

`wp-env` mounts the plugin rather than activating it. `"plugins": ["."]` would
make wp-env activate it during startup, and a failed activation then tears down
the whole stack — which turns any activation bug into an unbootable environment.
Activate it from the CLI once the stack is up:

```bash
npx wp-env run cli wp plugin activate autoscribe
```

## Checks

Both must pass before anything is merged. CI runs both on PHP 8.1, 8.2, and 8.3.

```bash
./vendor/bin/phpcs
npx wp-env run tests-cli --env-cwd=wp-content/plugins/autoscribe ./vendor/bin/phpunit
```

Do not pass `--parallel` to phpcs. Its progress counter reports worker batches
rather than files, which makes a partial run look like a complete one — a 33-file
run displaying "7 / 7" reads as a pass.

## Standards

- WordPress Coding Standards, `WordPress` and `WordPress-Docs`. `phpcs.xml.dist`
  records every deviation and the reason for it.
- **Never make a check pass by weakening the check.** No `phpcs:ignore`
  annotations, no skipped or deleted tests, no loosened ruleset. If a sniff fires,
  either the code is wrong or the sniff genuinely does not apply — and in the
  second case the exclusion belongs in `phpcs.xml.dist` with a comment explaining
  it, so the decision is reviewable.
- Escape on output, sanitize on input, nonce every form.
- Sanitize every model output before it reaches the database.
- Never hard-code a model ID anywhere that a model retirement would break the
  plugin. Model IDs are prompt configuration, and the provider adapters verify
  them against the provider's models endpoint before spending money on a call.

## Tests

Every HTTP call must be mocked. `tests/bootstrap.php` installs a tripwire on
`pre_http_request` at the lowest priority: any request that reaches it unhandled
throws, so a test that would otherwise hit a live provider fails loudly instead
of quietly passing against real data. Do not remove it or register a catch-all
mock that defeats it.

Tests must not depend on constants the development environment happens to
define. `.wp-env.json` supplies mock API keys, but CI has no `wp-config.php` — a
test needing a key should set one through `Key_Store::set()`.

## Adding a dependency

Ask first. Every production dependency ships to every install; every dev
dependency is another thing CI must resolve on three PHP versions.

## Releasing

The plugin header is the single source of truth for the version, and
`tests/VersionTest.php` enforces that the `VERSION` constant matches it. Bump the
header, bump the constant, update `CHANGELOG.md`, regenerate the `.pot`:

```bash
npx wp-env run cli --env-cwd=wp-content/plugins/autoscribe \
  wp i18n make-pot . languages/autoscribe.pot --slug=autoscribe --domain=autoscribe \
  --exclude=vendor,tests,dev,build,node_modules,bin
bin/build.sh
```

### Check the model catalogs before you tag

The first entry of each adapter's `suggested_models()` is what a prompt with no
model and no site default actually calls, so it is the plugin's real default
whatever section 2.2 says about model IDs being configuration. Model catalogs
change every few months and a default that has been retired is a plugin that
cannot generate anything.

Open each provider's own model list, confirm the first suggestion is still
generally available, and update the retrieval date recorded in the adapter's
docblock — whether or not the list changed. If you have a funded key, the
Settings screen's **Test connection** control makes the same call the pipeline
would, which is the only check that proves the string works rather than merely
appearing on a page.

- Anthropic: <https://docs.claude.com/en/docs/about-claude/models/overview>
- OpenAI: <https://platform.openai.com/docs/models>
- Google: <https://ai.google.dev/gemini-api/docs/models> and
  <https://ai.google.dev/gemini-api/docs/latest-model>
- DeepSeek: <https://api-docs.deepseek.com/quick_start/pricing>

This is deliberately not a test. A test that calls a provider fails when a
network does, needs a funded key in CI, and would put the suite's correctness in
somebody else's hands. `GoogleTest` instead pins the string this build claims, so
changing it is a deliberate act rather than a reordering nobody notices.
