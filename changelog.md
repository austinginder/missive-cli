# Changelog

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
