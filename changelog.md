# Changelog

## v1.3.0 — 2026-08-05

### New

- **`draft --attach=<paths>`** — attach one or more files to a draft. Comma-separated paths with `~` expansion, base64 encoded for Missive's `POST /v1/drafts`. Missive's 25-file and 10 MB payload limits are enforced before the request goes out rather than after the upload, and each file is logged with its size as it is attached.

### Fixed

- **Stop re-closing conversations that are already closed live** — Missive's personal `inbox=true` feed can return conversations that already have `closed_at` set, so trusting the list name alone marked them open locally and `process-emails` kept re-closing them, each pass adding another "Conversation closed." comment. Status is now derived from the payload (`closed_at`, then `users[me].closed`) and `close` skips the API post when the conversation is already closed, updating only the local database.

### Documentation

- **Readme caught up with the last two releases** — documents `draft --reply-all` (shipped in 1.1.0), `sync --force-bodies` and `sync --conversation=<id>` (shipped in 1.2.0), and the new `draft --attach`, all of which were implemented but undocumented.

## v1.2.0 — 2026-07-13

### New

- **`sync --conversation=<id>`** — sync a single conversation (partial ID OK) without paging the whole inbox. Use this to backfill incomplete threads instead of `--force` on the full inbox.
- **`sync --force-bodies`** — re-download full message bodies even when a local body already exists. Separated from `--force` so a refresh no longer re-pulls every body by default.

### Improved

- **Faster sync throttle** — removed the hard 1.0s per-request delay. Adaptive throttle starts at ~0.2s, slows only after HTTP 429, and eases back toward 0.1s on success.
- **Smarter incremental sync** — re-fetches when local bodies are empty, message counts drift, or remote activity is newer. Fixes incomplete threads (e.g. missing replies) without a full `--force` crawl.
- **Resilient body batches** — prefer 25-message batches, then retry at 10 before falling back to individual GETs.

## v1.1.0 — 2026-06-22

### New

- **CC / BCC / Reply-To capture** — `sync` now stores the `cc_fields`, `bcc_fields`, and `reply_to_fields` of every message, and `show` displays the Cc line in text, pretty, and JSON output. Existing databases migrate automatically.
- **`draft --reply-all`** — auto-fills To and Cc from the latest inbound message in a conversation (original sender + To recipients become To, original Cc becomes Cc). Excludes your own addresses (`--from` plus the optional `MISSIVE_MY_ADDRESSES` constant/env) and de-duplicates. `--to` is now optional when `--reply-all` is used. Explicit `--to`/`--cc` still take precedence and merge.
- **`sync --start-before=<datetime>`** — seed the pagination cursor to backfill older history instead of starting from now.
- **Team inbox + status tracking** — `sync` also syncs the team inbox when `MISSIVE_TEAM_ID` is set, tracks open/closed status transitions, and syncs closed conversations with `--full`.
- **`close --local`** — close a conversation only in the local database, skipping the Missive API.
- **`list` filters** — `--messages=<count>`, `--order=<asc|desc>`, `--after=<date>`, and `--before=<date>`, plus a Status column.

### Improved

- **Rate-limit handling** — the API client retries on HTTP 429 with `Retry-After`/exponential backoff and throttles requests during bulk syncs.
- **Database storage** — the private database directory is created automatically with secure (`0700`/`0600`) permissions.

## v1.0.0 — 2026-03-28

Initial release.

### Commands

- **sync** — Sync conversations from Missive to a local SQLite database. Supports `--timeframe`, `--full`, `--all-open`, and `--force` options. Syncs both personal and team inboxes.
- **list** — List synced conversations with filtering by status, subject, and classification.
- **search** — Search conversations by keyword across subjects, message bodies, or sender names. Supports `--field`, `--status`, `--before`, `--after`, and output as `--format=ids` for piping.
- **show** — Display conversation details from the local database. Includes `--pretty` TUI format, `--links` extraction, and JSON output.
- **draft** — Create email drafts or send immediately. Supports replies to existing conversations, CC/BCC, and body from file.
- **close** — Close one or more conversations in both Missive and the local database. Accepts multiple IDs and stdin piping.
- **comments** — Fetch conversation comments from the Missive API.
- **drafts** — List drafts in a conversation.
- **delete-draft** — Delete one or more drafts.
- **export** — Export open conversations as Markdown.
- **api** — Query any Missive REST API endpoint directly with GET, POST, PATCH, or DELETE.
- **endpoints** — Show the Missive API endpoint reference grouped by resource.
- **stats** — Show database statistics.

### Features

- Local SQLite database for fast offline reads and search
- Partial conversation ID matching across all commands
- Automatic rate limit handling with exponential backoff
- Self-contained GitHub updater for easy updates
- Configurable via `MISSIVE_API_KEY`, `MISSIVE_TEAM_ID`, and `MISSIVE_API_NAME` constants
