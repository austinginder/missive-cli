# Changelog

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
