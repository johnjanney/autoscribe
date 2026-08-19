# Decision log

Why AutoScribe is built the way it is.

Each entry records a decision that was not obvious, cost something to get right,
or departs from `docs/PROJECT-BRIEF.md`. The point is that the next person to
touch the code — including a future me — can tell a deliberate choice from an
accident, and knows what they would be undoing.

Entries are append-only. If a decision is reversed, the original stays and a new
entry supersedes it.

**Legend:** ✅ follows the brief · ⚠️ departs from the brief · 🔁 supersedes an
earlier entry

---

## Index

| # | Decision | |
|---|---|---|
| [D-01](#d-01) | Text and image providers are separate settings | ✅ |
| [D-02](#d-02) | Model IDs are editable text, never hard-coded | ✅ |
| [D-03](#d-03) | REST APIs, not MCP | ✅ |
| [D-04](#d-04) | Action Scheduler, not WP-Cron | ✅ |
| [D-05](#d-05) | Prompts are a custom post type; runs are a custom table | ✅ |
| [D-06](#d-06) | Settings live in four options, not one | ⚠️ |
| [D-07](#d-07) | The pipeline runs synchronously inside one action | ⚠️ |
| [D-08](#d-08) | The run row is the budget reservation | ⚠️ |
| [D-09](#d-09) | Action Scheduler does not retry, so the plugin does | ⚠️ |
| [D-09b](#d-09b) | A scheduled run is advanced one step per queued request | ✅ |
| [D-10](#d-10) | A retry opens a new run row | ✅ |
| [D-11](#d-11) | Duplicate detection counts drafts, not just published posts | ⚠️ |
| [D-12](#d-12) | `post_exists()` is not used | ⚠️ |
| [D-13](#d-13) | Executable blocks are stripped with their contents, before `wp_kses_post()` | ⚠️ |
| [D-14](#d-14) | Grounding sources are extracted by one tolerant walker | ⚠️ |
| [D-15](#d-15) | The `payload` column holds grounding sources | ⚠️ |
| [D-16](#d-16) | API keys prefer constants; stored keys detect salt rotation | ✅ |
| [D-17](#d-17) | A whole capability family, not one capability | ⚠️ |
| [D-18](#d-18) | Every prompt field is declared exactly once | ⚠️ |
| [D-19](#d-19) | "Run now" queues; it does not stream | ⚠️ |
| [D-20](#d-20) | The next-run readout is server-rendered, not live | ⚠️ |
| [D-21](#d-21) | Force human review is checked before any caller override | ✅ |
| [D-22](#d-22) | Uninstall preserves the flags that identify generated content | ⚠️ |
| [D-23](#d-23) | Uninstall sweeps an explicit key list, not a wildcard | ⚠️ |
| [D-24](#d-24) | No `phpcs:ignore` annotations, anywhere | ⚠️ |
| [D-25](#d-25) | `WordPress.Files.FileName` is disabled for PSR-4 | ⚠️ |
| [D-26](#d-26) | Filtered queries use one static statement with sentinel bindings | ⚠️ |
| [D-27](#d-27) | Date bounds use a wide sentinel range, never an empty string | ⚠️ |
| [D-28](#d-28) | A tripwire fails any test that makes an unmocked HTTP request | ⚠️ |
| [D-29](#d-29) | Tests supply their own API keys | ⚠️ |
| [D-30](#d-30) | The uninstall test disables the harness's table rewriting | ⚠️ |
| [D-31](#d-31) | CI does not use wp-env | ⚠️ |
| [D-32](#d-32) | `vendor/` is built, not committed | ✅ |
| [D-33](#d-33) | The version lives in the plugin header, guarded by a test | ✅ |
| [D-34](#d-34) | Versions understated the work until the scope was complete | ⚠️ |
| [D-35](#d-35) | Build artefacts accumulate rather than being wiped | ⚠️ |

---

## Architecture

### D-01
**Text and image providers are separate settings.** ✅

Anthropic has no image endpoint and DeepSeek's hosted API has none either. A
single "provider" setting would make the obvious configuration — Claude writes
the article, Google draws the picture — impossible to express.

Two independent slots, each with its own key, model, and adapter interface.
`Text_Provider_Interface` and `Image_Provider_Interface` share nothing.

*Consequence:* two key constants per vendor where the same key often serves both
(`AUTOSCRIBE_OPENAI_KEY`, `AUTOSCRIBE_OPENAI_IMAGE_KEY`). Slightly redundant,
but it lets text and images bill to different accounts.

### D-02
**Model IDs are editable text, never hard-coded.** ✅

Model IDs are retired on the provider's schedule, not ours. Anything that pins
one in code breaks the plugin the day it happens, and the fix requires a plugin
update the site owner cannot make themselves.

Every model field is free text with a datalist of suggestions. Before any paid
call the adapter checks the configured ID against the provider's `models`
endpoint, so a retirement surfaces as a clear error rather than a failed
generation.

### D-03
**REST APIs, not MCP.** ✅

In this plugin WordPress *is* the client: it holds the prompt, calls the model,
and writes the post. An MCP layer would need a persistent Node or Python process
and a transport most PHP hosts do not offer, and would add no capability.

Everything goes through `wp_remote_post()` behind an adapter interface. A new
provider is one class file.

### D-04
**Action Scheduler, not WP-Cron.** ✅

WP-Cron fires on page loads, so a low-traffic site drifts or stops, and its
recurring schedules are fixed intervals — "the second Tuesday of the month" is
not expressible. Action Scheduler gives a real queue, a run log, and
single-action scheduling that can compute its own next occurrence.

*Consequence:* the plugin ships Action Scheduler as a dependency, guarded with
`class_exists( 'ActionScheduler' )` because WooCommerce may already have loaded
it. Sites still need `DISABLE_WP_CRON` plus a real system cron entry, which is
documented prominently because nothing works properly without it.

---

## Data model

### D-05
**Prompts are a custom post type; runs are a custom table.** ✅

Prompts get CRUD, a list table, revisions, capability mapping, and nonces for
free, and there will never be many of them. Runs grow without bound and need
querying by prompt, date, and status plus summing cost — which is what a table
is for.

*Consequence:* the post type supports only `title` and `revisions`, so prompt
revisions capture almost nothing, since all the configuration is meta. Making
revisions meaningful would mean hooking meta into the revision system. Not done.

### D-06
**Settings live in four options, not one.** ⚠️

§3.2 asks for a single `autoscribe_settings` array. §8.1 contradicts it by
requiring API keys in their own encrypted store, so "one option" was never
achievable as written.

Four options: `autoscribe_settings` (review override, notification address,
retention, default models), `autoscribe_keys` (encrypted), `autoscribe_pricing`,
`autoscribe_global_budget_cents`.

The pricing table and the budget cap stay separate because both are read on
paths that never load the rest of the settings — the budget guard runs before
every paid call. Merging them now would be a migration for no benefit.

*Consequence:* uninstall has four options to clean up, not one. It missed three
of them for two releases (see D-23).

---

## Pipeline

### D-07
**The pipeline runs synchronously inside one action.** ⚠️

§5 specifies six separate Action Scheduler steps, each reading and writing
`runs.payload`, so that no single request risks a PHP timeout.

What is built is a `Generator` that runs budget check → topic proposal →
duplicate check → body → assemble → image → status in one call, invoked from one
queued action. The step classes exist and are separately testable, but they are
composed rather than individually scheduled.

*Why:* the split design's cost is real — six queue round-trips, state
serialised between each, and idempotency logic in every step — and its benefit
only materialises on hosts where a single 30–120 second request would time out.
The synchronous version is simpler to reason about and much simpler to test.

*Consequence:* **this is the decision most likely to need revisiting.** On a
shared host with a short `max_execution_time`, a long generation can be killed
mid-run. The step boundaries are already there, so converting is mechanical
rather than a rewrite — but it has not been done, and nothing currently detects
the failure mode. `runs.payload` was left unused by this choice (see D-15).

### D-08
**The run row is the budget reservation.** ⚠️

§7.4 says to check the cap before any paid call, which on its own is racy: two
Action Scheduler workers can both read a month-to-date total below the cap and
both proceed.

The run row is written *before* the first paid call with the estimated cost in
`cost_cents`, so a concurrent guard sees it. After the run, the estimate is
replaced with the measured cost from reported token usage.

*Consequence:* no new schema, no locking. A run that dies between reservation
and settlement leaves an estimate in place, which overstates spend slightly —
the right direction to be wrong in.

### D-09
**Action Scheduler does not retry, so the plugin does.** ⚠️

§5 says "Action Scheduler retries" and caps attempts at three. Action Scheduler
does not retry failed actions — it records the failure and stops.

`Retry_Policy` plus an `_autoscribe_attempt` meta key on the prompt: backoff at
5 minutes, 30 minutes, then hourly, capped at three attempts.

Classification is an **allowlist** of transient codes — transport failure, rate
limit, provider unavailable — and anything else is permanent. It was a denylist
of permanent codes until 1.0.2, which meant every code nobody had classified yet
was retried three times by default, including every code a later release or a
provider might introduce. Getting the default wrong in that direction costs
money; getting it wrong in this one fails a run that was going to fail anyway.
`autoscribe_transient_error_codes` filters the list.

*Consequence:* the attempt counter lives on the prompt rather than the run,
because a retry opens a new run row (D-10) and the count has to survive across
rows.

### D-10
**A retry opens a new run row.** ✅

Every step is idempotent keyed by `run_id`, so reusing the failed row for a retry
would make each step believe its work was already done and skip it, producing a
"successful" run that did nothing.

*Revisited in 1.1.0 and unchanged.* The pipeline split was expected to invert
this: if a run can resume from where it stopped, the argument went, a retry
should resume too. It does not follow. The step chain lives *within* one run — a
resumed run picks up its own row, which is what makes the idempotency useful —
while a retry is a fresh attempt at work that failed, and wants a clean row for
the same reason it always did. The stall sweeper resumes; retries still start
over.

### D-11
**Duplicate detection counts drafts, not just published posts.** ⚠️

§7.2 says to query the last N *published* posts. But §10 recommends review mode,
which saves everything as a draft. On the configuration the brief itself
recommends, a published-only query would never see anything, and the same
article would be regenerated at full price on every run.

Published, draft, pending, and future all count as covering a topic.

### D-12
**`post_exists()` is not used.** ⚠️

§7.2 names `post_exists()` as one of the three collision checks. It lives in
`wp-admin/includes/post.php`, which is not loaded in an Action Scheduler or
WP-CLI context — calling it there is a fatal error, in the exact context the
plugin runs in.

Title collision is checked with the plugin's own query instead. The other two
checks are exact key match and `similar_text()` above a filterable threshold.
That threshold defaults to **78, not the 82 §7.2 names** — a deviation this
document previously did not admit, because it went on quoting 82 after the code
had settled on 78. `similar_text()` compares characters rather than meaning, and
on hyphenated slugs it scores unrelated articles low and neighbouring ones high;
the real defence is the already-covered list injected into the proposal prompt,
so the numeric backstop is set slightly wider. §7.2 asks for the threshold to be
filterable and `autoscribe_topic_similarity_threshold` is, so a site that wants
the brief's 82 can have it in one line.

### D-13
**Executable blocks are stripped with their contents, before `wp_kses_post()`.** ⚠️

§5.2 says to run the body through `wp_kses_post()` and strip `<script>`,
`<style>`, `<iframe>`, and `on*` attributes. Doing only that leaves two visible
defects, both of which were observed: `wp_kses_post()` removes a `<script>` tag
but keeps its text node, so `alert("xss")` survives as body text; and stripping
only a dangerous scheme leaves `href="alert(1)"`, a junk link.

`Content_Sanitizer` therefore removes paired and self-closing executable
elements *with their contents*, removes attributes carrying dangerous schemes
*whole*, and only then calls `wp_kses_post()`. Content containing a surviving
`data:` or `javascript:` URI is rejected outright rather than published.

### D-14
**Grounding sources are extracted by one tolerant walker.** ⚠️

§7.1 requires recording the URLs a grounded call used. Anthropic returns
`web_search_result` blocks and `web_search_result_location` citations, OpenAI
returns `url_citation` annotations, Google returns `groundingChunks[].web.uri` —
three shapes at three depths, each of which would break independently when a
provider adds a wrapper level.

`Source_Extractor` walks the decoded body and collects any node that both
carries a URL and identifies itself as a citation or search result, either by
its `type` or by its parent key.

*Why tolerant:* an unrecognised source costs an incomplete audit list. A parser
that hard-fails on an unexpected shape costs the whole article. The asymmetry
decides it.

*Consequence:* this was a latent no-op for several releases — `Generation_Result`
accepted a `sources` parameter that no adapter ever passed, so the feature
existed in the type signature and nowhere else.

### D-15
**The `payload` column holds grounding sources.** ⚠️

§3.2 describes `runs.payload` as intermediate state passed between pipeline
steps. Because the pipeline is synchronous (D-07) there is no such state, and
the column was dead.

§7.1 also asks for source URLs to be stored on the run, so `payload` holds a
JSON object with a `sources` key.

*Consequence:* if D-07 is ever reversed and the pipeline is split into separate
actions, this column gains a second tenant and the two uses need reconciling.

---

## Security

### D-16
**API keys prefer constants; stored keys detect salt rotation.** ✅

Constants in `wp-config.php` keep the key out of the database and out of every
database backup. That is the documented recommendation, and a constant beats a
stored key when both exist.

Stored keys are encrypted with `sodium_crypto_secretbox` under a key derived via
`hash_hkdf` from `AUTH_KEY` and `SECURE_AUTH_KEY`. The README states plainly
that this is obfuscation against a leaked dump, not protection against server
compromise — anyone who can read the database can usually read `wp-config.php`.

Rotating those salts makes stored keys undecryptable. Rather than failing with
something cryptic, `Key_Store` fingerprints the salts and reports a distinct
`stale` state, which the settings screen surfaces as "enter it again".

Keys are never echoed back into the form. The field is always empty; only its
state is described.

### D-17
**A whole capability family, not one capability.** ⚠️

§8.2 names a single `autoscribe_manage_prompts` capability. A custom post type
consults a generated family (`edit_autoscribe_prompts`,
`delete_others_autoscribe_prompts`, and so on). Registering only the settings
capability would have left the post type on the default post capabilities —
meaning any Author could edit prompts, and therefore edit what gets sent to a
paid API and published.

Both sets are registered and granted together.

---

## Admin

### D-18
**Every prompt field is declared exactly once.** ⚠️

There are 28 prompt fields across five tabs. Describing each in the form markup
and again in the save handler is how a field ends up rendered but never
persisted: the two lists drift, nothing errors, and the value the user typed is
silently discarded.

`Prompt_Fields` is the single declaration — key, type, tab, label, default,
sanitiser, choices. Both the render pass and the save pass read from it, and a
test asserts the round trip for every field.

*Consequence:* adding a field is one entry, and the existing test covers it with
no edit. This was demonstrated when `append_sources` was added: the round-trip
test picked it up unchanged.

### D-09b
**A scheduled run is advanced one step per queued request.** ✅

§5 asks for this and 1.0.x did not do it: the whole pipeline ran inside one
action, so a host with a short `max_execution_time` could kill an article
part-way. Each request now carries at most one provider call.

Two consequences worth naming, because neither is obvious from the requirement:

*Splitting does not give recovery.* Action Scheduler records a killed action as
failed and stops rather than retrying — the same fact D-09 is about — so a killed
step leaves a run open with nothing queued to advance it. `Stall_Sweeper` is what
closes that, and a run is judged stalled by whether anything is queued to advance
it rather than by age, because age cannot tell a stalled run from a slow one.

*Splitting created a cost leak that had to be closed with it.* The budget
reservation is written before the first paid call and read by the cap while the
run is open, so an abandoned run held its estimate against the monthly cap for
ever. Giving up on a stalled run settles it from measured usage, which gives back
everything it did not spend.

### D-19
**"Run now" queues; it does not stream.** ⚠️

§9.2 asks for a Run now button that "streams the result". §2.4 puts a full run
at 30–120 seconds and is the entire reason the pipeline is queued. Holding an
admin request open that long is the failure the queue exists to prevent.

Run now enqueues an immediate action and returns straight away; the run log
reports the outcome.

*Consequence:* there is no live progress indication. The user queues a run and
then looks at the run log. Acceptable, but not what the brief pictured.

*Since 1.1.0* the gap between queueing and a finished post is larger, because the
run is advanced one step per queued request (D-09b). The run log's step column is
the progress indication the brief wanted a stream for — it names the last step
that finished.

### D-20
**The next-run readout is server-rendered, not live.** ⚠️

§9.2 asks for a readout that updates as the schedule controls change. That would
mean either recomputing in JavaScript — duplicating ordinal weekdays, month
ends, and daylight saving, exactly the logic §4.2 warns against reimplementing —
or an AJAX round trip to `Next_Run_Calculator`.

The readout is rendered server-side and reflects the *saved* schedule. The AJAX
round trip was not built.

*Consequence:* changing the schedule controls does not update the readout until
you save. This is a genuine gap against §9.2, not a reinterpretation of it.

### D-21
**Force human review is checked before any caller override.** ✅

§10 calls this the safety catch: one switch that stops every prompt publishing,
for the moment a provider changes behaviour or a prompt starts producing
garbage.

It is therefore evaluated *first* in `final_status()` — ahead of the explicit
status argument that WP-CLI and Run now can pass. A catch that a command-line
flag can step around is not a catch.

### D-22
**Uninstall preserves the flags that identify generated content.** ⚠️

Uninstall deliberately leaves generated posts and images in place — they are the
site owner's content. But the original implementation deleted every
`_autoscribe_%` post meta key, which included `_autoscribe_generated` (added by
§6 precisely so a human can find and bulk-delete AI images later) and
`_autoscribe_run_id` (added by §10 so the content stays auditable).

Keeping the content while destroying the only means of identifying it is not a
coherent uninstall.

`_autoscribe_generated`, `_autoscribe_run_id`, and `_autoscribe_topic_key`
survive. A test asserts both halves: plugin data goes, those three stay.

### D-23
**Uninstall sweeps an explicit key list, not a wildcard.** ⚠️

D-22 is only implementable with an explicit list — a `LIKE '_autoscribe_%'`
sweep cannot express "except these three".

*Consequence:* a new prompt field must be added to the list in `uninstall.php`
or it is left behind forever. `UninstallTest` writes every declared field to a
post and asserts the sweep clears them all, so the omission fails the suite
rather than shipping.

*Also fixed here:* the same sweep had been missing three options added in later
phases, and never deleted the prompt posts themselves — leaving them as
unreachable content of an unregistered post type.

---

## Code standards

### D-24
**No `phpcs:ignore` annotations, anywhere.** ⚠️

A suppression comment is invisible in review and permanent in practice. Four
times during development a sniff fired and the reflex was to annotate; each time
the annotation was removed and the code changed instead:

| Sniff | What was done instead |
|---|---|
| `DirectDatabaseQuery` in `uninstall.php` | `%i` identifier placeholder |
| `WP_Filesystem` in the image sideloader | Used `WP_Filesystem` |
| `slow_db_query` on a meta query | Dropped the meta query, filtered in PHP |
| `NonceVerification` in the meta box save | Moved the nonce check inline, into the same function that reads `$_POST` |
| `PreparedSQL.NotPrepared` in the run query | Rewrote as one static statement (D-26) |

Where a sniff genuinely does not apply, the exclusion goes in `phpcs.xml.dist`
with a comment explaining why — visible in one place, reviewable, and scoped to
the narrowest possible pattern. Every current exclusion is documented there.

### D-25
**`WordPress.Files.FileName` is disabled for PSR-4.** ⚠️

The plugin autoloads through Composer PSR-4, which requires file names mirroring
class names (`src/Plugin.php` for `AutoScribe\Plugin`). The sniff predates
Composer and demands lowercase hyphenated `class-*.php`, which PSR-4 cannot
resolve. The two conventions are mutually exclusive.

The sniff is set to severity 0. No other sniff is relaxed for style reasons.

### D-26
**Filtered queries use one static statement with sentinel bindings.** ⚠️

The run log filters by prompt, status, and month, any of which may be absent.
Concatenating optional `WHERE` fragments means handing `$wpdb->prepare()` a
variable — the shape that hides an injection, and the reason the sniff fires.

Instead the statement is a fixed literal and every filter is bound, including
the disabled ones, each switched off by its own sentinel comparison
(`%d = 0 OR prompt_id = %d`).

*Consequence:* marginally less index-friendly. The table is small, the query is
admin-only and paginated, and the trade buys a statement that cannot be
malformed.

### D-27
**Date bounds use a wide sentinel range, never an empty string.** ⚠️

The first version of D-26 disabled the month filter by binding `''` and
comparing `started_at >= ''`. An empty string is not a datetime: MariaDB
tolerates the comparison, **MySQL in strict mode rejects it and the query
returns nothing at all**.

Every test passed locally against MariaDB and seven failed in CI against MySQL —
and the month-filtered tests passed in CI precisely because they were the only
ones that never took that path.

Disabled date bounds now widen to `1000-01-01` – `9999-12-31`.

*Lesson recorded:* the tests were correct and ran against the wrong database.
This is the argument for CI on the engine you deploy to.

---

## Testing

### D-28
**A tripwire fails any test that makes an unmocked HTTP request.** ⚠️

Requiring every test to remember to register a mock is a rule that gets broken
silently — a forgotten mock means a test quietly hitting a live provider, and
passing.

`tests/bootstrap.php` registers a `pre_http_request` filter at `PHP_INT_MAX`
that throws on anything reaching it unhandled. Mocks run earlier and get first
refusal.

*Consequence:* a test asserting that no provider call happens can simply not
install a mock — reaching the assertion is itself proof.

### D-29
**Tests supply their own API keys.** ⚠️

`.wp-env.json` defines `AUTOSCRIBE_ANTHROPIC_KEY`, so the suite passed locally
while depending on an environment variable. In CI, where no `wp-config.php`
exists, it would have failed for an environmental reason rather than a real one.

Tests call `Key_Store::set()` themselves. Constants still win when present, so
this is a floor rather than an override.

### D-30
**The uninstall test disables the harness's table rewriting.** ⚠️

`WP_UnitTestCase` filters every query to rewrite `CREATE TABLE` and `DROP TABLE`
into their `TEMPORARY` forms, which is how it isolates tests. A `DROP` of a real
table therefore silently no-ops — so a test asserting the runs table is gone
would pass whether or not the drop worked. A false green in the direction that
matters.

`UninstallTest` removes those filters for the duration and puts them back in a
`finally`. The reason is written in the file so it is not reinstated as a
simplification.

---

## Tooling and release

### D-31
**CI does not use wp-env.** ⚠️

`.wp-env.json` pins a single `phpVersion`, which makes an 8.1/8.2/8.3 matrix
impossible. CI uses `shivammathur/setup-php`, a MySQL service, and WordPress
core downloaded in the workflow.

The test config is written into `vendor/wp-phpunit/wp-phpunit/` because that is
where wp-phpunit's own bootstrap looks when no config constant is defined.
Pointing at it with `WP_TESTS_CONFIG_FILE_PATH` instead would mean the plugin
defining an unprefixed global — which the coding standard rejects and which
cannot be fixed by prefixing, since the name belongs to core.

### D-32
**`vendor/` is built, not committed.** ✅

§12 offers a choice. Committing it is not workable once there are both
production dependencies that must ship (Action Scheduler, cron-expression) and
development dependencies that must not (PHPCS, PHPUnit, wp-phpunit): a committed
`vendor/` either ships the dev tooling to every install, or is wiped by the next
`composer install`.

`bin/build.sh` stages a copy and runs `composer install --no-dev` in it.

*Consequence:* a plain `git clone` into `wp-content/plugins` does not produce a
working plugin. Documented in both README and INSTRUCTIONS.

### D-33
**The version lives in the plugin header, guarded by a test.** ✅

WordPress reads the header, the update system compares it, and the build script
names the zip from it — so the header is the source of truth. The
`AutoScribe\VERSION` constant mirrors it because the User-Agent needs the value
at runtime, and parsing the plugin file on every request would be a real cost
for no benefit.

Two copies can drift, so `tests/VersionTest.php` asserts they match. A release
with a bumped header and a stale constant fails the suite.

### D-34
**Versions understated the work until the scope was complete.** ⚠️

Development ran through 0.1.0 to 0.8.0 before 1.0.0, with each phase bumping the
minor version. 1.0.0 was deliberately withheld while §9's admin surface was
missing, even though the plugin generated and published posts correctly from
0.3.0 onwards.

A 1.0 that a user finds is missing its settings screen damages trust more than a
0.6 that under-promises.

### D-35
**Build artefacts accumulate rather than being wiped.** ⚠️

`bin/build.sh` originally cleared `build/` on every run, so only the most recent
zip existed. That makes it impossible to diff what was actually shipped against
what is about to ship.

The script now clears only the staging tree, replaces just the zip for the
version being built, and lists what is on disk when it finishes. `build/` stays
gitignored — binaries do not belong in version control, and the durable home for
a released zip is its GitHub release.
