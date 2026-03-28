# Missive CLI

WP-CLI commands for syncing and managing [Missive](https://missiveapp.com) inbox conversations. Syncs your Missive inbox to a local SQLite database, giving you fast offline search, conversation reading, email drafting, and bulk operations from the command line.

Designed to work alongside AI coding assistants like [Claude Code](https://docs.anthropic.com/en/docs/claude-code), which can read the local database directly and run CLI commands to search, triage, draft replies, and close conversations without touching the Missive API for every read.

## Requirements

- WordPress with [WP-CLI](https://wp-cli.org/)
- PHP 8.0+
- A [Missive API key](https://missiveapp.com/help/api-documentation/rest-endpoints)

## Installation

1. Copy the plugin to your WordPress plugins directory:

```
wp-content/plugins/missive-cli/
```

2. Add your API key to `wp-config.php`:

```php
define( 'MISSIVE_API_KEY', 'your-api-key' );
define( 'MISSIVE_TEAM_ID', 'optional-team-id' );    // enables team inbox sync
define( 'MISSIVE_API_NAME', 'Your Name' );           // display name for close actions
```

3. Activate the plugin:

```bash
wp plugin activate missive-cli
```

4. Run your initial sync:

```bash
# Sync the last week (default)
wp missive sync

# Or backfill your full history
wp missive sync --timeframe=10y
```

The database is stored at `../private/missive.db` relative to your WordPress root.

## Commands

### sync

Sync conversations from Missive to the local database.

```bash
wp missive sync                        # Last 7 days (default)
wp missive sync --timeframe=1d         # Last 24 hours
wp missive sync --timeframe=7d         # Last 7 days
wp missive sync --timeframe=7d --full  # Include closed conversations
wp missive sync --all-open             # All open conversations regardless of age
wp missive sync --force                # Re-fetch all message bodies
```

For daily use, `wp missive sync --timeframe=7d` keeps the database current. The sync is incremental and only fetches message bodies for conversations with new activity.

### list

List synced conversations from the local database.

```bash
wp missive list
wp missive list --status=open
wp missive list --subject="Site Removal" --status=open
wp missive list --preview                # Show message preview snippets
wp missive list --unclassified
wp missive list --format=ids             # Output IDs only (for piping)
wp missive list --limit=100
```

### search

Search conversations by keyword in subjects, message bodies, or sender names.

```bash
wp missive search "504 Gateway"
wp missive search "kinsta" --field=body
wp missive search "launchkits" --field=from
wp missive search "Site Removal" --status=open
wp missive search "Monitor:" --status=open --before=2026-02-14
wp missive search "Monitor:" --after=2026-03-01 --format=count
wp missive search "Injection" --format=ids
```

Use `search` for keyword lookups. The `list` command only supports `--subject=` filtering.

The `--format=ids` output is designed for piping into other commands:

```bash
wp missive close $(wp missive search "storage limit" --status=open --format=ids | tr '\n' ' ')
```

Search by sender with `--field=from`:

```bash
wp missive close $(wp missive search "Hover" --field=from --status=open --limit=200 --format=ids | tr '\n' ' ')
```

### show

Display a conversation with its messages. Uses the local database, no API call needed.

```bash
wp missive show abc123                  # Partial ID matching
wp missive show abc123 --pretty         # TUI-style boxed format with colors
wp missive show abc123 --full           # Full message bodies (no truncation)
wp missive show abc123 --links          # Extract URLs from message bodies
wp missive show abc123 --format=json    # JSON output
```

Prefer `wp missive show` over hitting the API directly. For comments (not stored locally), use `wp missive comments`.

### draft

Create an email draft or send immediately.

```bash
# New email
wp missive draft \
  --to="Name <email@example.com>" \
  --from="Your Name <you@example.com>" \
  --subject="Subject line" \
  --body="<p>HTML body content</p>"

# Reply to a conversation
wp missive draft \
  --to="Name <email@example.com>" \
  --from="Your Name <you@example.com>" \
  --subject="Re: Original subject" \
  --conversation=abc123 \
  --body="Thanks for reaching out."

# Body from file
wp missive draft \
  --to="Name <email@example.com>" \
  --subject="Report" \
  --body-file=./email.html

# Send immediately
wp missive draft \
  --to="Name <email@example.com>" \
  --subject="Urgent" \
  --body="Message" \
  --send
```

| Option | Required | Description |
|--------|----------|-------------|
| `--to` | Yes | Recipient (format: `email` or `Name <email>`) |
| `--subject` | No* | Email subject (*required for new conversations) |
| `--body` | Yes** | Email body (HTML or plain text) |
| `--body-file` | Yes** | Path to file containing body (**alternative to `--body`) |
| `--conversation` | No | Conversation ID to reply to (supports partial ID) |
| `--from` | No | Sender email (must match a Missive alias) |
| `--cc` | No | CC recipients (comma-separated) |
| `--bcc` | No | BCC recipients (comma-separated) |
| `--send` | No | Send immediately instead of creating draft |

Tips for drafting:

- **HTML formatting:** Use `<br>` for line breaks and `<br><br>` for paragraph spacing. Missive's editor collapses `<p>` tags into single line breaks.
- **Replies need a subject:** The API does not auto-populate the subject on replies. Always include `--subject="Re: Original subject"`.
- **Check reply-to:** For automated notification emails, the "from" address is often a no-reply. Check the conversation's `reply_to_fields` to find the correct recipient.

### close

Close one or more conversations in both Missive and the local database.

```bash
wp missive close abc123
wp missive close abc123 def456 ghi789   # Multiple IDs in one call
wp missive close abc123 --username="Bot Name"

# Pipe from search
wp missive search "Injection" --format=ids | xargs wp missive close
```

Pass multiple IDs to a single `close` call rather than chaining separate commands.

### comments

Fetch comments from the Missive API for a conversation.

```bash
wp missive comments abc123
wp missive comments abc123 --all        # Paginate through all comments
```

### drafts / delete-draft

Manage drafts on a conversation.

```bash
wp missive drafts abc123                # List drafts
wp missive delete-draft <draft-id>      # Delete a draft
wp missive delete-draft id1 id2 id3     # Delete multiple
```

### export

Export open conversations as Markdown.

```bash
wp missive export
wp missive export --full                # Full message bodies
wp missive export --timeframe=1d        # Only recent activity
```

### api

Query any Missive API endpoint directly. Supports GET, POST, PATCH, and DELETE.

```bash
wp missive api /users/me
wp missive api "/conversations?inbox=true&limit=10"
wp missive api /conversations/<id>/messages
wp missive api /shared_labels
wp missive api /posts --method=POST --data='{"posts":{"conversation":"<id>","close":true}}'
```

For complex JSON payloads, use `--data-file=` to pass a JSON file instead of inline `--data`.

See the [API cookbook](#api-cookbook) section below for real-world examples.

### endpoints

Show the Missive API endpoint reference, grouped by resource.

```bash
wp missive endpoints
wp missive endpoints conversations
wp missive endpoints drafts
wp missive endpoints posts
```

### stats

Show database statistics.

```bash
wp missive stats
```

## Using with Claude Code

The local SQLite database is what makes this CLI powerful with Claude Code. Instead of hitting the Missive API for every read, Claude Code can query the database directly and use CLI commands to act on conversations.

### Setup

Add a `CLAUDE.md` to your project with drafting instructions so Claude Code knows how to compose emails:

```markdown
Use `wp missive draft` to create email drafts in Missive.

### Replying to a conversation

wp missive draft \
  --to="Name <email@example.com>" \
  --from="Your Name <you@example.com>" \
  --conversation=<partial-or-full-id> \
  --subject="Re: Original subject" \
  --body="Message body here"
```

### Workflow: Email Triage

A typical triage session with Claude Code:

1. **Sync recent conversations:**

```bash
wp missive sync --timeframe=7d
```

2. **Batch-close noise categories first.** Search by subject or sender, review the matches, then close in bulk:

```bash
# Find and close storage alerts
wp missive search "storage limit exceeded" --status=open
wp missive close $(wp missive search "storage limit exceeded" --status=open --format=ids | tr '\n' ' ')

# Close by sender
wp missive close $(wp missive search "Hover" --field=from --status=open --limit=200 --format=ids | tr '\n' ' ')
```

3. **Work through actionable items.** Read conversations locally, draft replies, close when done:

```bash
wp missive show abc123 --pretty
wp missive draft --to="client@example.com" --conversation=abc123 --subject="Re: Their question" --body="Your reply"
wp missive close abc123
```

### Tips

- Use `--limit=200` when bulk closing by sender or category. The default limit of 50 will miss older conversations. Re-run until the count is 0.
- Use `wp missive show` to read conversations locally. Only fall back to the API if a conversation is not synced yet.
- Use `wp missive comments <id>` for internal comments (these are not stored in the local database).
- Conversation IDs support partial matching everywhere. The first 8 characters usually suffice.

## API Cookbook

The `wp missive api` command gives you direct access to the full [Missive REST API](https://missiveapp.com/help/api-documentation/rest-endpoints). Here are practical examples for common tasks that go beyond the built-in commands.

### Conversations

```bash
# List 10 most recent inbox conversations
wp missive api "/conversations?inbox=true&limit=10"

# List closed conversations
wp missive api "/conversations?closed=true&limit=10"

# List conversations in a team inbox
wp missive api "/conversations?team_inbox=<team-id>&limit=10"

# List conversations by shared label
wp missive api "/conversations?shared_label=<label-id>"

# Filter conversations by contact email
wp missive api "/conversations?inbox=true&email=user@example.com"

# Filter conversations by contact domain
wp missive api "/conversations?inbox=true&domain=example.com"

# Get a single conversation (full metadata)
wp missive api /conversations/<id>

# Get messages in a conversation
wp missive api /conversations/<id>/messages

# Get comments on a conversation
wp missive api /conversations/<id>/comments

# Get drafts in a conversation
wp missive api /conversations/<id>/drafts

# Merge two conversations
wp missive api /conversations/<source-id>/merge --method=POST \
  --data='{"target":"<destination-id>","subject":"Optional new subject"}'
```

### Messages

```bash
# Get a single message with full body and headers
wp missive api /messages/<id>

# Get multiple messages in one call (comma-separated)
wp missive api /messages/<id1>,<id2>,<id3>

# Find messages by email Message-ID header
wp missive api "/messages?email_message_id=<message-id-header>"
```

### Posts (Conversation Actions)

Posts are the primary way to manage conversation state through the API. Each post leaves a visible trace in the conversation.

```bash
# Close a conversation
wp missive api /posts --method=POST \
  --data='{"posts":{"conversation":"<id>","close":true,"username":"Bot","notification":{"title":"Closed","body":"Resolved"}}}'

# Reopen a conversation
wp missive api /posts --method=POST \
  --data='{"posts":{"conversation":"<id>","reopen":true,"text":"Reopening for follow-up","username":"Bot","notification":{"title":"Reopened","body":"Needs follow-up"}}}'

# Move to inbox (unarchive)
wp missive api /posts --method=POST \
  --data='{"posts":{"conversation":"<id>","add_to_inbox":true,"text":"Moved to inbox","username":"Bot","notification":{"title":"Inbox","body":"Moved"}}}'

# Add a shared label
wp missive api /posts --method=POST \
  --data='{"posts":{"conversation":"<id>","add_shared_labels":["<label-id>"],"text":"Labeled","username":"Bot","notification":{"title":"Labeled","body":"Added label"}}}'

# Assign a user
wp missive api /posts --method=POST \
  --data='{"posts":{"conversation":"<id>","add_assignees":["<user-id>"],"text":"Assigned","username":"Bot","notification":{"title":"Assigned","body":"User assigned"}}}'

# Delete a post
wp missive api /posts/<post-id> --method=DELETE
```

### Drafts (Sending Email via API)

```bash
# Send an email immediately
wp missive api /drafts --method=POST \
  --data='{"drafts":{"send":true,"subject":"Hello","body":"World!","to_fields":[{"address":"user@example.com"}],"from_field":{"address":"you@example.com","name":"Your Name"}}}'

# Create a draft in an existing conversation
wp missive api /drafts --method=POST \
  --data='{"drafts":{"conversation":"<id>","subject":"Re: Topic","body":"Reply body","to_fields":[{"address":"user@example.com"}],"from_field":{"address":"you@example.com"}}}'

# Schedule an email for later (Unix timestamp)
wp missive api /drafts --method=POST \
  --data='{"drafts":{"send_at":1700000000,"subject":"Scheduled","body":"Sent later","to_fields":[{"address":"user@example.com"}],"from_field":{"address":"you@example.com"}}}'

# Delete a draft
wp missive api /drafts/<draft-id> --method=DELETE
```

### Contacts

```bash
# Search contacts
wp missive api "/contacts?contact_book=<book-id>&search=John"

# Get a single contact
wp missive api /contacts/<id>

# List contact books
wp missive api /contact_books

# List contact groups
wp missive api "/contact_groups?contact_book=<book-id>&kind=organization"
```

### Shared Labels

```bash
# List all shared labels
wp missive api /shared_labels

# List labels for a specific organization
wp missive api "/shared_labels?organization=<org-id>"

# Create a shared label
wp missive api /shared_labels --method=POST \
  --data='{"shared_labels":[{"name":"Urgent","color":"#ff0000","organization":"<org-id>"}]}'
```

### Tasks

```bash
# List open tasks
wp missive api "/tasks?organization=<org-id>&state=todo"

# List tasks assigned to a user
wp missive api "/tasks?assignee=<user-id>&state=in_progress"

# Create a standalone task
wp missive api /tasks --method=POST \
  --data='{"tasks":{"organization":"<org-id>","title":"Follow up","assignees":["<user-id>"]}}'

# Update a task state
wp missive api /tasks/<task-id> --method=PATCH \
  --data='{"tasks":{"state":"closed"}}'
```

### Other

```bash
# Current authenticated user
wp missive api /users/me

# List all users in your organization
wp missive api /users

# List teams
wp missive api /teams

# List organizations
wp missive api /organizations

# List canned responses
wp missive api /responses
```

### Notes

- **Conversation state** (close, reopen, assign, label) is managed through the `/posts` endpoint, not by patching conversations directly. There is no `PATCH /conversations/:id`.
- **Sending email** uses the `/drafts` endpoint with `send: true`.
- **HTML body formatting:** Use `<div>` or `<br>` for spacing, not `<p>` tags. Missive collapses `<p>` tags into single-spaced lines.
- **Rate limiting:** The API allows 5 requests/second. The CLI handles 429 responses automatically with exponential backoff.
- **Pagination:** List endpoints use `until` (a timestamp) for cursor-based pagination, not `offset`. The `last_activity_at` of the last item becomes the `until` for the next page.

## Database Schema

The SQLite database stores three tables:

- **conversations** -- id, subject, authors, assignees, shared_labels, status (open/closed), timestamps
- **messages** -- id, conversation_id, subject, preview, body, from_name, from_address, to_fields, delivered_at
- **classifications** -- conversation_id, priority, category, reasoning, suggested_action, model

## Configuration

| Constant | Required | Description |
|----------|----------|-------------|
| `MISSIVE_API_KEY` | Yes | Your Missive API bearer token |
| `MISSIVE_TEAM_ID` | No | Team ID for syncing team inbox alongside personal inbox |
| `MISSIVE_API_NAME` | No | Display name shown when closing conversations (defaults to "Missive CLI") |

All constants can also be set as environment variables.

## License

MIT
