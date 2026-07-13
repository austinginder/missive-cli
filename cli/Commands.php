<?php
/**
 * WP-CLI Commands for Missive
 */

namespace MissiveCLI\CLI;

use MissiveCLI\Database;
use MissiveCLI\Remote\Missive;

class Commands {

    private ?Database $db = null;

    /**
     * Get database instance (lazy loaded)
     */
    private function getDb(): Database {
        if ( $this->db === null ) {
            $this->db = new Database();
        }
        return $this->db;
    }

    /**
     * Parse a duration string like "2d", "24h", "1w" into seconds
     */
    private function parseDuration( string $duration ): int {
        $duration = strtolower( trim( $duration ) );

        if ( preg_match( '/^(\d+)\s*(m|min|minutes?|h|hr|hours?|d|days?|w|weeks?|y|years?)$/', $duration, $matches ) ) {
            $value = (int) $matches[1];
            $unit = $matches[2][0];

            return match ( $unit ) {
                'm' => $value * 60,
                'h' => $value * 3600,
                'd' => $value * 86400,
                'w' => $value * 604800,
                'y' => $value * 31536000,
                default => $value * 3600,
            };
        }

        if ( is_numeric( $duration ) ) {
            return (int) $duration * 3600;
        }

        \WP_CLI::error( "Invalid duration format: $duration (use e.g., 2d, 24h, 1w)" );
        return 86400;
    }

    /**
     * Format seconds as human-readable duration
     */
    private function formatDuration( int $seconds ): string {
        if ( $seconds >= 31536000 && $seconds % 31536000 === 0 ) {
            $years = $seconds / 31536000;
            return $years . ' year' . ( $years > 1 ? 's' : '' );
        }
        if ( $seconds >= 604800 && $seconds % 604800 === 0 ) {
            $weeks = $seconds / 604800;
            return $weeks . ' week' . ( $weeks > 1 ? 's' : '' );
        }
        if ( $seconds >= 86400 && $seconds % 86400 === 0 ) {
            $days = $seconds / 86400;
            return $days . ' day' . ( $days > 1 ? 's' : '' );
        }
        if ( $seconds >= 3600 && $seconds % 3600 === 0 ) {
            $hours = $seconds / 3600;
            return $hours . ' hour' . ( $hours > 1 ? 's' : '' );
        }
        $minutes = $seconds / 60;
        return $minutes . ' minute' . ( $minutes > 1 ? 's' : '' );
    }

    /**
     * Sync open conversations from Missive inbox
     *
     * ## OPTIONS
     *
     * [--timeframe=<duration>]
     * : How far back to sync (e.g., 1d, 1w, 12h)
     * ---
     * default: 1w
     * ---
     *
     * [--all-open]
     * : Sync all open conversations regardless of timeframe
     *
     * [--full]
     * : Sync both open and closed conversations (default: open only)
     *
     * [--force]
     * : Re-check every conversation in range even if last_activity_at is unchanged
     *
     * [--force-bodies]
     * : Re-download full message bodies even when a local body already exists
     *
     * [--conversation=<id>]
     * : Sync only this conversation (partial ID OK). Ignores timeframe/inbox paging.
     *
     * [--start-before=<datetime>]
     * : Start syncing from this point instead of now (e.g., 2025-01-15, "2025-01-15 08:00:00")
     *
     * ## EXAMPLES
     *
     *     wp missive sync
     *     wp missive sync --timeframe=1d
     *     wp missive sync --timeframe=7d --full
     *     wp missive sync --timeframe=12h --force
     *     wp missive sync --all-open
     *     wp missive sync --conversation=eab2e106
     *     wp missive sync --timeframe=10y --start-before="2024-06-01"
     *
     * @when after_wp_load
     */
    public function sync( $args, $assoc_args ) {
        $timeframe    = $assoc_args['timeframe'] ?? '1w';
        $force        = isset( $assoc_args['force'] );
        $force_bodies = isset( $assoc_args['force-bodies'] );
        $full         = isset( $assoc_args['full'] );
        $all_open     = isset( $assoc_args['all-open'] );

        // Adaptive throttle: start fast (~0.2s), only slow down on 429.
        Missive::setAdaptiveThrottle( true );
        Missive::setThrottleDelay( 0.2 );

        // Surgical single-conversation sync (no inbox pagination).
        if ( ! empty( $assoc_args['conversation'] ) ) {
            $this->syncSingleConversation( $assoc_args['conversation'], $force_bodies || $force );
            return;
        }

        // Parse --start-before to seed the initial pagination cursor
        $start_before = null;
        if ( ! empty( $assoc_args['start-before'] ) ) {
            $start_before = strtotime( $assoc_args['start-before'] );
            if ( $start_before === false ) {
                \WP_CLI::error( "Could not parse --start-before value: {$assoc_args['start-before']}" );
            }
        }

        $flags = [];
        if ( $force ) {
            $flags[] = 'force';
        }
        if ( $force_bodies ) {
            $flags[] = 'force-bodies';
        }
        $flag_note = $flags ? ' (' . implode( ', ', $flags ) . ')' : '';

        if ( $all_open ) {
            $since = 0;
            \WP_CLI::log( "Syncing all open conversations{$flag_note}..." );
        } else {
            $duration_seconds = $this->parseDuration( $timeframe );
            $since = time() - $duration_seconds;
            $human_duration = $this->formatDuration( $duration_seconds );
            $scope = $full ? 'open + closed' : 'open';
            \WP_CLI::log( "Syncing $scope conversations from the last $human_duration{$flag_note}..." );
            \WP_CLI::log( "Cutoff: " . date( 'Y-m-d H:i:s', $since ) );
        }

        if ( $start_before ) {
            \WP_CLI::log( "Starting from: " . date( 'Y-m-d H:i:s', $start_before ) . " (skipping newer conversations)" );
        }

        $db = $this->getDb();
        $synced_ids = [];
        $total_messages = 0;

        // Determine which inbox types to sync
        $inboxes = [ [ 'inbox' => 'true' ] ];
        $team_id = defined( 'MISSIVE_TEAM_ID' ) ? MISSIVE_TEAM_ID : getenv( 'MISSIVE_TEAM_ID' );
        if ( $team_id ) {
            $inboxes[] = [ 'team_inbox' => $team_id ];
        }

        foreach ( $inboxes as $inbox_params ) {
            $inbox_label = isset( $inbox_params['team_inbox'] ) ? "team inbox ({$inbox_params['team_inbox']})" : 'personal inbox';

            // Always sync open conversations
            \WP_CLI::log( "Fetching $inbox_label (open)..." );
            try {
                list( $ids, $msg_count ) = $this->syncInbox( $inbox_params, $since, $force, $force_bodies, 'open', $start_before );
                $synced_ids = array_merge( $synced_ids, $ids );
                $total_messages += $msg_count;
            } catch ( \Exception $e ) {
                \WP_CLI::warning( ucfirst( $inbox_label ) . " error: " . $e->getMessage() );
            }

            // Sync closed conversations when --full is set
            if ( $full ) {
                $closed_key = isset( $inbox_params['team_inbox'] ) ? 'team_closed' : 'closed';
                $closed_value = isset( $inbox_params['team_inbox'] ) ? $inbox_params['team_inbox'] : 'true';
                $closed_params = [ $closed_key => $closed_value ];

                \WP_CLI::log( "Fetching $inbox_label (closed)..." );
                try {
                    list( $ids, $msg_count ) = $this->syncInbox( $closed_params, $since, $force, $force_bodies, 'closed', $start_before );
                    $synced_ids = array_merge( $synced_ids, $ids );
                    $total_messages += $msg_count;
                } catch ( \Exception $e ) {
                    \WP_CLI::warning( ucfirst( $inbox_label ) . " (closed) error: " . $e->getMessage() );
                }
            }
        }

        \WP_CLI::success( sprintf(
            "Synced %d conversations (%d messages). Final throttle: %ss",
            count( array_unique( $synced_ids ) ),
            $total_messages,
            round( Missive::getThrottleDelay(), 2 )
        ) );
    }

    /**
     * Sync a single conversation by full or partial ID.
     */
    private function syncSingleConversation( string $partial_id, bool $force_bodies = false ): void {
        $db = $this->getDb();

        // Prefer resolving via local DB partial match when possible.
        $conv_id = $db->findByPartialId( $partial_id ) ?: $partial_id;

        \WP_CLI::log( "Syncing conversation $conv_id..." );

        try {
            $response = Missive::get( "/conversations/{$conv_id}" );
            $list     = $response['conversations'] ?? [];
            $conv     = $list[0] ?? null;
            if ( ! $conv || empty( $conv['id'] ) ) {
                // Try partial search against local open list as a last resort message.
                \WP_CLI::error( "Conversation not found via API: $partial_id" );
            }
            $conv_id = $conv['id'];
        } catch ( \Exception $e ) {
            \WP_CLI::error( "Could not fetch conversation: " . $e->getMessage() );
        }

        $status = ! empty( $conv['closed_at'] ) ? 'closed' : 'open';
        $previous_status = $db->upsertConversation( $conv, $status );
        if ( $previous_status !== null ) {
            $subject = $conv['subject'] ?? $conv['latest_message_subject'] ?? substr( $conv['id'], 0, 8 );
            \WP_CLI::log( "  Status changed: $subject ($previous_status -> $status)" );
        }

        try {
            $msg_count = $this->syncConversationMessages( $conv, $force_bodies );
        } catch ( \Exception $e ) {
            \WP_CLI::error( "Could not fetch messages: " . $e->getMessage() );
        }

        $display = $conv['subject'] ?? $conv['latest_message_subject'] ?? $conv_id;
        \WP_CLI::success( "Synced $display ($msg_count messages)." );
    }

    /**
     * Sync an inbox and return list of synced conversation IDs
     */
    private function syncInbox( array $options, int $since, bool $force = false, bool $force_bodies = false, string $status = 'open', ?int $start_before = null ): array {
        $db = $this->getDb();
        $synced_ids = [];
        $messages_synced = 0;
        $until = $start_before;
        $page = 0;

        do {
            $page++;
            $params = array_merge( $options, [ 'limit' => 50 ] );
            if ( $until ) {
                $params['until'] = $until;
            }

            $response = Missive::get( '/conversations', $params );
            $conversations = $response['conversations'] ?? [];

            if ( ! empty( $conversations ) ) {
                $oldest_activity = end( $conversations )['last_activity_at'] ?? null;
                $oldest_date = $oldest_activity ? date( 'Y-m-d H:i', (int) $oldest_activity ) : '?';
                \WP_CLI::log( "  Page $page: " . count( $conversations ) . " conversations (back to $oldest_date)" );
            }

            if ( empty( $conversations ) ) {
                break;
            }

            foreach ( $conversations as $conv ) {
                $activity_raw = $conv['last_activity_at'] ?? 0;
                $activity_time = is_int( $activity_raw ) ? $activity_raw : (int) strtotime( $activity_raw );

                if ( $activity_time < $since ) {
                    \WP_CLI::log( "  Stopping: activity " . date( 'Y-m-d H:i', $activity_time ) . " before cutoff" );
                    break 2;
                }

                $synced_ids[] = $conv['id'];
                $remote_count = isset( $conv['messages_count'] ) ? (int) $conv['messages_count'] : null;

                // New activity, incomplete local bodies/count, or --force.
                $needs_sync = $force || $db->needsMessageSync( $conv['id'], $activity_time, $remote_count );

                // Always update conversation metadata
                $previous_status = $db->upsertConversation( $conv, $status );
                if ( $previous_status !== null ) {
                    $subject = $conv['subject'] ?? $conv['latest_message_subject'] ?? substr( $conv['id'], 0, 8 );
                    \WP_CLI::log( "  Status changed: $subject ($previous_status -> $status)" );
                }

                if ( $needs_sync ) {
                    try {
                        $msg_count = $this->syncConversationMessages( $conv, $force_bodies );
                        $messages_synced += $msg_count;
                    } catch ( \Exception $e ) {
                        \WP_CLI::warning( "Could not fetch messages for {$conv['id']}: " . $e->getMessage() );
                    }

                    // Build display: subject or first author
                    $display = $conv['subject'] ?? $conv['latest_message_subject'] ?? '';
                    if ( ! $display ) {
                        $authors = $conv['authors'] ?? [];
                        if ( ! empty( $authors ) ) {
                            $display = $authors[0]['name'] ?? $authors[0]['address'] ?? '';
                        }
                    }
                    \WP_CLI::log( "  Synced: " . ( $display ?: '(unknown)' ) );
                }
            }

            $until = end( $conversations )['last_activity_at'] ?? null;

        } while ( ! empty( $conversations ) && count( $conversations ) >= 50 );

        return [ $synced_ids, $messages_synced ];
    }

    /**
     * Fetch messages for one conversation, fill bodies, upsert. Returns messages upserted.
     */
    private function syncConversationMessages( array $conv, bool $force_bodies = false ): int {
        $db = $this->getDb();
        $conv_id = $conv['id'];

        $msg_response = Missive::get( "/conversations/{$conv_id}/messages" );
        $messages = $msg_response['messages'] ?? [];

        // IDs needing a full body: missing body, or explicit --force-bodies.
        // Also re-fetch when we already have a local row with empty body.
        $local_empty = $this->localEmptyBodyIds( $conv_id );
        $ids_to_fetch = [];
        foreach ( $messages as $msg ) {
            if ( empty( $msg['id'] ) ) {
                continue;
            }
            $id = $msg['id'];
            $remote_empty = empty( $msg['body'] );
            if ( $force_bodies || $remote_empty || isset( $local_empty[ $id ] ) ) {
                $ids_to_fetch[] = $id;
            }
        }
        $ids_to_fetch = array_values( array_unique( $ids_to_fetch ) );

        $full_messages = [];
        // Prefer smaller batches (25) to reduce timeout fallbacks under load.
        foreach ( array_chunk( $ids_to_fetch, 25 ) as $batch ) {
            try {
                $batch_response = Missive::get( "/messages/" . implode( ',', $batch ) );
                $fetched = $batch_response['messages'] ?? [];
                // Single message returns object, batch returns array
                if ( ! empty( $fetched ) && ! isset( $fetched[0] ) ) {
                    $fetched = [ $fetched ];
                }
                foreach ( $fetched as $full_msg ) {
                    if ( ! empty( $full_msg['id'] ) ) {
                        $full_messages[ $full_msg['id'] ] = $full_msg;
                    }
                }
            } catch ( \Exception $e ) {
                \WP_CLI::warning( "Batch fetch failed, retrying smaller then individual: " . $e->getMessage() );
                // Second chance: half-size batches
                foreach ( array_chunk( $batch, 10 ) as $small ) {
                    try {
                        $batch_response = Missive::get( "/messages/" . implode( ',', $small ) );
                        $fetched = $batch_response['messages'] ?? [];
                        if ( ! empty( $fetched ) && ! isset( $fetched[0] ) ) {
                            $fetched = [ $fetched ];
                        }
                        foreach ( $fetched as $full_msg ) {
                            if ( ! empty( $full_msg['id'] ) ) {
                                $full_messages[ $full_msg['id'] ] = $full_msg;
                            }
                        }
                        continue;
                    } catch ( \Exception $e_small ) {
                        // fall through to individual
                    }
                    foreach ( $small as $msg_id ) {
                        try {
                            $full_msg = Missive::get( "/messages/{$msg_id}" );
                            $single = $full_msg['messages'] ?? $full_msg['message'] ?? $full_msg;
                            if ( ! empty( $single ) && ! isset( $single[0] ) && ! empty( $single['id'] ) ) {
                                $full_messages[ $single['id'] ] = $single;
                            }
                        } catch ( \Exception $e2 ) {
                            \WP_CLI::warning( "Could not fetch message {$msg_id}: " . $e2->getMessage() );
                        }
                    }
                }
            }
        }

        $count = 0;
        foreach ( $messages as $msg ) {
            if ( ! empty( $msg['id'] ) && isset( $full_messages[ $msg['id'] ] ) ) {
                $msg = array_merge( $msg, $full_messages[ $msg['id'] ] );
            }
            // Preserve an existing non-empty local body if remote list payload lacks one
            // and we didn't re-fetch (avoids wiping bodies on partial list responses).
            if ( empty( $msg['body'] ) && ! empty( $msg['id'] ) ) {
                $existing = $this->getLocalMessageBody( $msg['id'] );
                if ( $existing !== null && $existing !== '' ) {
                    $msg['body'] = $existing;
                }
            }
            $db->upsertMessage( $msg, $conv_id );
            $count++;
        }

        return $count;
    }

    /**
     * Map of message IDs in a conversation that have empty/null bodies locally.
     *
     * @return array<string,true>
     */
    private function localEmptyBodyIds( string $conversation_id ): array {
        $db = $this->getDb();
        // Access via a small query through Database would be cleaner; use PDO via reflection-free public helper if available.
        // Fall back to show-style: re-query through a dedicated method.
        return $db->getEmptyBodyMessageIds( $conversation_id );
    }

    private function getLocalMessageBody( string $message_id ): ?string {
        return $this->getDb()->getMessageBody( $message_id );
    }

    /**
     * Export open conversations as Markdown
     *
     * ## OPTIONS
     *
     * [--full]
     * : Include complete message bodies (default: 800 char limit)
     *
     * [--timeframe=<duration>]
     * : Filter by activity timeframe (e.g., 1d, 1w, 12h)
     *
     * ## EXAMPLES
     *
     *     wp missive export
     *     wp missive export --full
     *     wp missive export --timeframe=1d
     *
     * @when after_wp_load
     */
    public function export( $args, $assoc_args ) {
        $full = isset( $assoc_args['full'] );
        $since = null;
        $duration_label = '';

        if ( isset( $assoc_args['timeframe'] ) ) {
            $duration_seconds = $this->parseDuration( $assoc_args['timeframe'] );
            $since = time() - $duration_seconds;
            $duration_label = ' (last ' . $this->formatDuration( $duration_seconds ) . ')';
        }

        $db = $this->getDb();
        $conversations = $db->getOpenConversations( $since );

        if ( empty( $conversations ) ) {
            \WP_CLI::log( "No open conversations to export." );
            return;
        }

        $body_limit = $full ? 0 : 800;
        $output = "# Open Inbox - " . date( 'Y-m-d H:i' ) . $duration_label . "\n\n";
        $output .= "**" . count( $conversations ) . " open conversations**\n\n";

        foreach ( $conversations as $conv ) {
            $messages = $conv['messages'] ?? [];

            // Use conversation subject, or first message subject as fallback
            $subject = $conv['subject'] ?? '';
            if ( ! $subject && ! empty( $messages ) ) {
                $subject = $messages[0]['subject'] ?? '';
            }
            $subject = $subject ?: '(no subject)';

            $url = $conv['web_url'] ?? '';
            $activity = $conv['last_activity_at'] ? date( 'Y-m-d H:i', $conv['last_activity_at'] ) : '';

            $authors = json_decode( $conv['authors'], true ) ?: [];
            $author_str = '';
            if ( ! empty( $authors ) ) {
                $first = $authors[0];
                $author_str = ( $first['name'] ?? '' ) . ' <' . ( $first['address'] ?? '' ) . '>';
            }

            $output .= "---\n\n";
            if ( $url ) {
                $output .= "## [$subject]($url)\n";
            } else {
                $output .= "## $subject\n";
            }
            $output .= "**From:** $author_str | **Last:** $activity\n\n";

            foreach ( $messages as $msg ) {
                $from = ( $msg['from_name'] ?? '' ) . ' <' . ( $msg['from_address'] ?? '' ) . '>';
                $date = $msg['delivered_at'] ? date( 'M j H:i', $msg['delivered_at'] ) : '';

                $body = $msg['body'] ?? $msg['preview'] ?? '';
                if ( $body ) {
                    $body = strip_tags( $body );
                    $body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    $body = preg_replace( '/\s+/', ' ', $body );
                    $body = trim( $body );
                    if ( $body_limit > 0 && strlen( $body ) > $body_limit ) {
                        $body = substr( $body, 0, $body_limit ) . '...';
                    }
                }

                $output .= "**$from** ($date):\n";
                $output .= "> " . str_replace( "\n", "\n> ", wordwrap( $body, 100 ) ) . "\n\n";
            }
        }

        echo $output;
    }

    /**
     * Query any Missive API endpoint
     *
     * Sends a request to the Missive REST API (v1). All endpoints are relative
     * to https://mail.missiveapp.com/api/v1. Run `wp missive endpoints` for a
     * full reference of available endpoints.
     *
     * ## OPTIONS
     *
     * <endpoint>
     * : API endpoint path (e.g., /conversations, /messages/:id)
     *
     * [--method=<method>]
     * : HTTP method (GET, POST, PATCH, DELETE)
     * ---
     * default: GET
     * ---
     *
     * [--data=<json>]
     * : JSON payload for POST/PATCH requests
     *
     * [--data-file=<path>]
     * : Path to file containing JSON payload (alternative to --data)
     *
     * ## COMMON ENDPOINTS
     *
     *     # List conversations from your inbox
     *     wp missive api "/conversations?inbox=true&limit=10"
     *
     *     # Get messages in a conversation (full bodies)
     *     wp missive api /conversations/<id>/messages
     *
     *     # Get a single message with headers and body
     *     wp missive api /messages/<id>
     *
     *     # Find messages by email Message-ID header
     *     wp missive api "/messages?email_message_id=<message-id>"
     *
     *     # List shared labels
     *     wp missive api /shared_labels
     *
     *     # Close a conversation (via posts endpoint)
     *     wp missive api /posts --method=POST --data='{"posts":{"conversation":"<id>","close":true}}'
     *
     *     # List teams
     *     wp missive api /teams
     *
     *     # Current user info
     *     wp missive api /users/me
     *
     * ## NOTES
     *
     *     Conversation state changes (close, reopen, assign, label) are done
     *     through the /posts endpoint with action params, not by patching
     *     conversations directly. Use `wp missive endpoints` for details.
     *
     * @when after_wp_load
     */
    public function api( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( "Usage: wp missive api <endpoint>" );
        }

        $endpoint = $args[0];
        $method = strtoupper( $assoc_args['method'] ?? 'GET' );

        // Parse JSON data from --data or --data-file
        $data = null;
        if ( isset( $assoc_args['data'] ) ) {
            $data = json_decode( $assoc_args['data'], true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                \WP_CLI::error( "Invalid JSON in --data: " . json_last_error_msg() );
            }
        } elseif ( isset( $assoc_args['data-file'] ) ) {
            $file_path = $assoc_args['data-file'];
            if ( ! file_exists( $file_path ) ) {
                \WP_CLI::error( "File not found: $file_path" );
            }
            $contents = file_get_contents( $file_path );
            if ( $contents === false ) {
                \WP_CLI::error( "Could not read file: $file_path" );
            }
            $data = json_decode( $contents, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                \WP_CLI::error( "Invalid JSON in --data-file: " . json_last_error_msg() );
            }
        }

        \WP_CLI::log( "$method $endpoint\n" );

        try {
            $response = match ( $method ) {
                'GET'    => Missive::get( $endpoint ),
                'POST'   => Missive::post( $endpoint, $data ?? [] ),
                'PATCH'  => Missive::patch( $endpoint, $data ?? [] ),
                'DELETE' => Missive::delete( $endpoint ),
                default  => throw new \Exception( "Unsupported method: $method. Use GET, POST, PATCH, or DELETE." ),
            };

            if ( $response !== null ) {
                \WP_CLI::log( json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            }

            if ( $method === 'DELETE' ) {
                \WP_CLI::success( "Deleted successfully." );
            }
        } catch ( \Exception $e ) {
            \WP_CLI::error( "API error: " . $e->getMessage() );
        }
    }

    /**
     * List synced conversations
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Limit results
     * ---
     * default: 50
     * ---
     *
     * [--subject=<pattern>]
     * : Filter by subject (substring match)
     *
     * [--status=<status>]
     * : Filter by status (open or closed)
     *
     * [--messages=<count>]
     * : Filter by message count (e.g., 1, 1-3, 4+)
     *
     * [--unclassified]
     * : Show only unclassified conversations
     *
     * [--preview]
     * : Show a preview snippet of the latest message
     *
     * [--order=<order>]
     * : Sort order by last activity (asc or desc)
     * ---
     * default: desc
     * options:
     *   - asc
     *   - desc
     * ---
     *
     * [--after=<date>]
     * : Only show conversations with activity after this date (YYYY-MM-DD)
     *
     * [--before=<date>]
     * : Only show conversations with activity before this date (YYYY-MM-DD)
     *
     * [--format=<format>]
     * : Output format (table, ids, or count)
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp missive list
     *     wp missive list --preview
     *     wp missive list --limit=100
     *     wp missive list --subject="Site Removal" --status=open
     *     wp missive list --subject="Injection detected" --format=ids
     *     wp missive list --unclassified
     *     wp missive list --messages=1 --status=open
     *     wp missive list --messages=1-3
     *     wp missive list --messages=4+
     *     wp missive list --status=open --after=2026-03-01
     *     wp missive list --status=open --after=2026-03-01 --format=count
     *
     * @when after_wp_load
     */
    public function list( $args, $assoc_args ) {
        $filters = [];

        if ( isset( $assoc_args['unclassified'] ) ) {
            $filters['unclassified'] = true;
        }

        if ( isset( $assoc_args['subject'] ) ) {
            $filters['subject'] = $assoc_args['subject'];
        }

        if ( isset( $assoc_args['status'] ) ) {
            $filters['status'] = $assoc_args['status'];
        }

        if ( isset( $assoc_args['messages'] ) ) {
            $msg_filter = $assoc_args['messages'];
            if ( preg_match( '/^(\d+)$/', $msg_filter, $m ) ) {
                // Exact: --messages=1
                $filters['messages_min'] = (int) $m[1];
                $filters['messages_max'] = (int) $m[1];
            } elseif ( preg_match( '/^(\d+)-(\d+)$/', $msg_filter, $m ) ) {
                // Range: --messages=1-3
                $filters['messages_min'] = (int) $m[1];
                $filters['messages_max'] = (int) $m[2];
            } elseif ( preg_match( '/^(\d+)\+$/', $msg_filter, $m ) ) {
                // Minimum: --messages=4+
                $filters['messages_min'] = (int) $m[1];
            }
        }

        if ( isset( $assoc_args['after'] ) ) {
            $filters['since'] = strtotime( $assoc_args['after'] . ' 00:00:00' );
        }

        if ( isset( $assoc_args['before'] ) ) {
            $filters['before'] = strtotime( $assoc_args['before'] . ' 23:59:59' );
        }

        $format = $assoc_args['format'] ?? 'table';
        $filters['limit'] = $format === 'count' ? null : (int) ( $assoc_args['limit'] ?? 50 );

        if ( isset( $assoc_args['order'] ) ) {
            $filters['order'] = strtolower( $assoc_args['order'] ) === 'asc' ? 'ASC' : 'DESC';
        }

        $db = $this->getDb();
        $conversations = $db->getConversations( $filters );

        if ( empty( $conversations ) ) {
            if ( $format === 'count' ) {
                echo "0\n";
                return;
            }
            \WP_CLI::log( "No conversations found." );
            return;
        }

        if ( $format === 'count' ) {
            \WP_CLI::log( count( $conversations ) );
            return;
        }

        if ( $format === 'ids' ) {
            foreach ( $conversations as $conv ) {
                echo substr( $conv['id'], 0, 8 ) . "\n";
            }
            return;
        }

        $show_preview = isset( $assoc_args['preview'] );
        $truncate = $format === 'table';

        $rows = [];
        foreach ( $conversations as $conv ) {
            $authors = json_decode( $conv['authors'], true ) ?: [];
            $author_str = '';
            if ( ! empty( $authors ) ) {
                $first_author = $authors[0];
                $author_str = $first_author['name'] ?? $first_author['address'] ?? '';
            }

            $subject = $conv['subject'] ?: $conv['message_subject'] ?? '(no subject)';

            $row = [
                'ID'         => $truncate ? substr( $conv['id'], 0, 8 ) . '...' : $conv['id'],
                'Subject'    => $truncate ? mb_substr( $subject, 0, 50 ) : $subject,
                'From'       => $truncate ? mb_substr( $author_str, 0, 25 ) : $author_str,
                'Activity'   => date( 'Y-m-d H:i', $conv['last_activity_at'] ),
                'Msgs'       => $conv['messages_count'] ?? 0,
                'Status'     => $conv['status'] ?? 'open',
                'Classified' => $conv['has_classification'] > 0 ? 'Yes' : 'No',
            ];

            if ( $show_preview ) {
                $preview = $conv['latest_preview'] ?? '';
                $row['Preview'] = $truncate ? mb_substr( $preview, 0, 80 ) : $preview;
            }

            $rows[] = $row;
        }

        $columns = [ 'ID', 'Subject', 'From', 'Activity', 'Msgs', 'Status', 'Classified' ];
        if ( $show_preview ) {
            $columns[] = 'Preview';
        }

        \WP_CLI\Utils\format_items( $format, $rows, $columns );
    }

    /**
     * Show conversation details
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more conversation IDs (supports partial matching)
     *
     * [--full]
     * : Show full message bodies without truncation
     *
     * [--pretty]
     * : Render in a TUI-style boxed format with colors
     *
     * [--links]
     * : Extract and display only the URLs found in message bodies
     *
     * [--format=<format>]
     * : Output format (text or json)
     * ---
     * default: text
     * ---
     *
     * ## EXAMPLES
     *
     *     wp missive show abc123
     *     wp missive show abc123 def456 ghi789
     *     wp missive show abc123 --full
     *     wp missive show abc123 --pretty
     *     wp missive show abc123 --links
     *     wp missive show abc123 --format=json
     *     wp missive search "Injection" --format=ids | xargs wp missive show --pretty
     *
     * @when after_wp_load
     */
    public function show( $args, $assoc_args ) {
        if ( empty( $args ) ) {
            \WP_CLI::error( "Usage: wp missive show <conversation_id> [<conversation_id>...]" );
        }

        $db     = $this->getDb();
        $format = $assoc_args['format'] ?? 'text';
        $pretty = isset( $assoc_args['pretty'] );
        $full   = isset( $assoc_args['full'] ) || $format === 'json' || $pretty;
        $links  = isset( $assoc_args['links'] );

        // Resolve all IDs upfront
        $conversations = [];
        $not_found     = [];
        foreach ( $args as $input_id ) {
            $id = $input_id;
            if ( strlen( $id ) < 36 ) {
                $full_id = $db->findByPartialId( $id );
                if ( $full_id ) {
                    $id = $full_id;
                }
            }
            $conv = $db->getConversation( $id );
            if ( $conv ) {
                $conversations[] = $conv;
            } else {
                $not_found[] = $input_id;
            }
        }

        if ( ! empty( $not_found ) ) {
            foreach ( $not_found as $nf ) {
                \WP_CLI::warning( "Conversation not found: $nf" );
            }
        }

        if ( empty( $conversations ) ) {
            \WP_CLI::error( "No conversations found." );
        }

        // Links extraction mode
        if ( $links ) {
            $urls = [];
            foreach ( $conversations as $conv ) {
                foreach ( $conv['messages'] as $msg ) {
                    if ( ! empty( $msg['body'] ) ) {
                        // Extract href URLs from anchor tags
                        preg_match_all( '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $msg['body'], $matches );
                        if ( ! empty( $matches[1] ) ) {
                            $urls = array_merge( $urls, $matches[1] );
                        }
                        // Extract bare URLs not in tags
                        preg_match_all( '/(?<!href=["\'])(https?:\/\/[^\s<>"\']+)/i', $msg['body'], $bare );
                        if ( ! empty( $bare[1] ) ) {
                            $urls = array_merge( $urls, $bare[1] );
                        }
                    }
                }
            }
            $urls = array_unique( $urls );
            // Filter out tracking, unsubscribe, and image proxy URLs
            $urls = array_filter( $urls, function( $url ) {
                return ! preg_match( '/sendgrid\.net|list-manage\.com|mailchimp\.com|email\.mg\.|camo\.missiveusercontent\.com|\.png$|\.jpg$|\.gif$|\.webp$/i', $url );
            } );
            if ( empty( $urls ) ) {
                \WP_CLI::log( "No links found." );
            } else {
                foreach ( $urls as $url ) {
                    echo $url . "\n";
                }
            }
            return;
        }

        // JSON output
        if ( $format === 'json' ) {
            $output = array_map( function( $conv ) {
                return [
                    'id'              => $conv['id'],
                    'subject'         => $conv['subject'] ?? null,
                    'web_url'         => $conv['web_url'] ?? null,
                    'status'          => $conv['status'] ?? 'open',
                    'last_activity_at' => $conv['last_activity_at'],
                    'authors'         => json_decode( $conv['authors'], true ) ?: [],
                    'classification'  => $conv['classification'],
                    'messages'        => array_map( function( $msg ) {
                        return [
                            'id'           => $msg['id'],
                            'from_name'    => $msg['from_name'] ?? null,
                            'from_address' => $msg['from_address'] ?? null,
                            'to_fields'    => json_decode( $msg['to_fields'], true ) ?: [],
                            'cc_fields'    => json_decode( $msg['cc_fields'] ?? '', true ) ?: [],
                            'bcc_fields'   => json_decode( $msg['bcc_fields'] ?? '', true ) ?: [],
                            'reply_to_fields' => json_decode( $msg['reply_to_fields'] ?? '', true ) ?: [],
                            'subject'      => $msg['subject'] ?? null,
                            'preview'      => $msg['preview'] ?? null,
                            'body'         => $msg['body'] ?? null,
                            'delivered_at' => $msg['delivered_at'],
                        ];
                    }, $conv['messages'] ),
                ];
            }, $conversations );
            // Single ID: output object directly for backwards compatibility
            if ( count( $output ) === 1 ) {
                $output = $output[0];
            }
            echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
            return;
        }

        // Pretty TUI output
        if ( $pretty ) {
            foreach ( $conversations as $conv ) {
                $this->showPretty( $conv );
            }
            return;
        }

        // Text output
        foreach ( $conversations as $ci => $conv ) {
            if ( $ci > 0 ) {
                \WP_CLI::log( "" );
            }
            \WP_CLI::log( "=== Conversation ===" );
            \WP_CLI::log( "ID: " . $conv['id'] );
            \WP_CLI::log( "Subject: " . ( $conv['subject'] ?? '(no subject)' ) );
            \WP_CLI::log( "URL: " . ( $conv['web_url'] ?? 'N/A' ) );
            \WP_CLI::log( "Status: " . ( $conv['status'] ?? 'open' ) );
            \WP_CLI::log( "Last Activity: " . date( 'Y-m-d H:i:s', $conv['last_activity_at'] ) );

            $authors = json_decode( $conv['authors'], true ) ?: [];
            if ( ! empty( $authors ) ) {
                \WP_CLI::log( "Authors:" );
                foreach ( $authors as $author ) {
                    $name = $author['name'] ?? '';
                    $addr = $author['address'] ?? '';
                    \WP_CLI::log( "  - $name <$addr>" );
                }
            }

            if ( $conv['classification'] ) {
                \WP_CLI::log( "\n=== Classification ===" );
                \WP_CLI::log( "Priority: " . $conv['classification']['priority'] );
                \WP_CLI::log( "Category: " . $conv['classification']['category'] );
                if ( $conv['classification']['reasoning'] ) {
                    \WP_CLI::log( "Reasoning: " . $conv['classification']['reasoning'] );
                }
                if ( $conv['classification']['suggested_action'] ) {
                    \WP_CLI::log( "Suggested Action: " . $conv['classification']['suggested_action'] );
                }
            }

            \WP_CLI::log( "\n=== Messages (" . count( $conv['messages'] ) . ") ===" );
            foreach ( $conv['messages'] as $i => $msg ) {
                \WP_CLI::log( "\n--- Message " . ( $i + 1 ) . " ---" );
                \WP_CLI::log( "From: " . ( $msg['from_name'] ?? '' ) . " <" . ( $msg['from_address'] ?? '' ) . ">" );
                $cc_str = $this->formatFieldList( $msg['cc_fields'] ?? null );
                if ( $cc_str !== '' ) {
                    \WP_CLI::log( "Cc: " . $cc_str );
                }
                \WP_CLI::log( "Date: " . ( $msg['delivered_at'] ? date( 'Y-m-d H:i:s', $msg['delivered_at'] ) : 'N/A' ) );
                \WP_CLI::log( "Subject: " . ( $msg['subject'] ?? '(no subject)' ) );

                if ( $msg['preview'] ) {
                    \WP_CLI::log( "Preview: " . $msg['preview'] );
                }

                if ( $msg['body'] ) {
                    $body = strip_tags( $msg['body'] );
                    $body = html_entity_decode( $body );
                    $body = preg_replace( '/\s+/', ' ', $body );
                    $body = trim( $body );
                    if ( ! $full && strlen( $body ) > 500 ) {
                        $body = substr( $body, 0, 500 ) . '...';
                    }
                    \WP_CLI::log( "Body:\n" . $body );
                }
            }
        }
    }

    /**
     * Render conversation in TUI-style boxed format
     */
    private function showPretty( array $conv ): void {
        $width = min( (int) exec( 'tput cols' ) ?: 80, 100 );
        $inner = $width - 4; // padding inside box

        $dim    = "\033[2m";
        $bold   = "\033[1m";
        $cyan   = "\033[36m";
        $yellow = "\033[33m";
        $green  = "\033[32m";
        $white  = "\033[37m";
        $reset  = "\033[0m";

        $subject = $conv['subject'] ?? '(no subject)';
        $status  = strtoupper( $conv['status'] ?? 'open' );
        $status_color = $status === 'OPEN' ? $green : $dim;

        // Top border
        echo "\n";
        echo $dim . '  ' . str_repeat( '─', $width - 2 ) . $reset . "\n";

        // Subject line
        $status_tag = " [{$status}]";
        $subj_width = $inner - mb_strlen( $status_tag );
        $subj_display = mb_strlen( $subject ) > $subj_width ? mb_substr( $subject, 0, $subj_width - 1 ) . '…' : $subject;
        $padding = $inner - mb_strlen( $subj_display ) - mb_strlen( $status_tag );
        echo "  {$bold}{$white}" . $subj_display . $reset . str_repeat( ' ', max( 0, $padding ) ) . $status_color . $status_tag . $reset . "\n";

        // Metadata
        $authors = json_decode( $conv['authors'], true ) ?: [];
        if ( ! empty( $authors ) ) {
            $first = $authors[0];
            $from_str = ( $first['name'] ?? '' ) . ' <' . ( $first['address'] ?? '' ) . '>';
            echo "  {$dim}From:{$reset}  {$from_str}\n";
        }
        echo "  {$dim}Date:{$reset}  " . date( 'D, M j Y g:ia', $conv['last_activity_at'] ) . "\n";
        echo "  {$dim}ID:{$reset}    " . substr( $conv['id'], 0, 8 ) . "\n";

        // Divider
        echo $dim . '  ' . str_repeat( '─', $width - 2 ) . $reset . "\n";

        // Messages
        foreach ( $conv['messages'] as $i => $msg ) {
            $from_name = $msg['from_name'] ?? '';
            $from_addr = $msg['from_address'] ?? '';
            $date_str  = $msg['delivered_at'] ? date( 'M j, g:ia', $msg['delivered_at'] ) : '';

            // Message header
            echo "\n";
            $header = "  {$cyan}{$bold}" . ( $from_name ?: $from_addr ) . $reset;
            echo $header . "  {$dim}" . $date_str . $reset . "\n";

            // To fields
            $to_fields = json_decode( $msg['to_fields'], true ) ?: [];
            if ( ! empty( $to_fields ) ) {
                $to_parts = [];
                foreach ( $to_fields as $to ) {
                    $to_parts[] = $to['name'] ?? $to['address'] ?? '';
                }
                echo "  {$dim}To: " . implode( ', ', $to_parts ) . $reset . "\n";
            }

            // Cc fields
            $cc_str = $this->formatFieldList( $msg['cc_fields'] ?? null );
            if ( $cc_str !== '' ) {
                echo "  {$dim}Cc: " . $cc_str . $reset . "\n";
            }

            echo "\n";

            // Body
            if ( $msg['body'] ) {
                $body = $msg['body'];

                // Strip style blocks, scripts, and head
                $body = preg_replace( '/<(style|script|head)[^>]*>.*?<\/\1>/is', '', $body );

                // Strip CSS that leaks outside style tags (e.g. Intercom emails)
                $body = preg_replace( '/(\.|#|@)\w[\w\-]*\s*\{[^}]*\}/s', '', $body );
                $body = preg_replace( '/[\w\-]+\s*\{[^}]*\}/s', '', $body );

                // Strip images and hidden elements
                $body = preg_replace( '/<img[^>]*>/i', '', $body );

                // Convert links before stripping tags
                $body = preg_replace( '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $body );

                // Convert list items
                $body = preg_replace( '/<li[^>]*>/i', '  • ', $body );

                // Convert headings
                $body = preg_replace( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', "\n$1\n", $body );

                // Convert br to newline
                $body = preg_replace( '/<br\s*\/?>/i', "\n", $body );

                // Block-level elements get a single newline
                $body = preg_replace( '/<\/?(p|div|tr|table|thead|tbody|section|article|header|footer|blockquote|ul|ol|li|td|th|dt|dd)[^>]*>/i', "\n", $body );

                // Strip remaining tags
                $body = strip_tags( $body );
                $body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

                // Clean up whitespace
                $body = str_replace( "\xC2\xA0", ' ', $body );
                $body = preg_replace( '/[ \t]+/', ' ', $body );
                $body = preg_replace( '/^ +$/m', '', $body ); // lines with only spaces
                $body = preg_replace( '/\n{3,}/', "\n\n", $body );
                $body = trim( $body );

                // Word wrap and indent
                $lines = explode( "\n", $body );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( $line === '' ) {
                        echo "\n";
                        continue;
                    }
                    $wrapped = wordwrap( $line, $inner, "\n", true );
                    foreach ( explode( "\n", $wrapped ) as $wline ) {
                        echo "  " . $wline . "\n";
                    }
                }
            } elseif ( $msg['preview'] ) {
                echo "  {$dim}" . wordwrap( $msg['preview'], $inner, "\n  ", true ) . $reset . "\n";
            }

            // Message divider (if not last)
            if ( $i < count( $conv['messages'] ) - 1 ) {
                echo "\n" . $dim . '  ' . str_repeat( '· ', ( $width - 2 ) / 2 ) . $reset . "\n";
            }
        }

        // Bottom border
        echo "\n" . $dim . '  ' . str_repeat( '─', $width - 2 ) . $reset . "\n";

        // Footer
        $url = $conv['web_url'] ?? '';
        if ( $url ) {
            echo "  {$dim}" . $url . $reset . "\n";
        }
        echo "\n";
    }

    /**
     * Parse email address string into name/address components
     * Handles: "email@example.com" or "Name <email@example.com>"
     */
    private function parseEmailAddress( string $input ): array {
        $input = trim( $input );

        // Match "Name <email>" format
        if ( preg_match( '/^(.+?)\s*<([^>]+)>$/', $input, $matches ) ) {
            return [
                'name'    => trim( $matches[1] ),
                'address' => trim( $matches[2] ),
            ];
        }

        // Simple email format
        return [ 'address' => $input ];
    }

    /**
     * Parse comma-separated email list into array of address objects
     */
    private function parseEmailList( string $input ): array {
        $emails = array_map( 'trim', explode( ',', $input ) );
        $result = [];

        foreach ( $emails as $email ) {
            if ( $email !== '' ) {
                $result[] = $this->parseEmailAddress( $email );
            }
        }

        return $result;
    }

    /**
     * Format an array (or JSON string) of {name, address} fields into a
     * human-readable "Name <addr>, ..." string. Returns '' when empty.
     */
    private function formatFieldList( $value ): string {
        if ( is_string( $value ) ) {
            $value = json_decode( $value, true ) ?: [];
        }
        if ( ! is_array( $value ) || empty( $value ) ) {
            return '';
        }

        $parts = [];
        foreach ( $value as $field ) {
            $name = $field['name'] ?? '';
            $addr = $field['address'] ?? '';
            if ( $addr === '' ) {
                continue;
            }
            $parts[] = ( $name && $name !== $addr ) ? "$name <$addr>" : $addr;
        }

        return implode( ', ', $parts );
    }

    /**
     * Remove duplicate recipients by address (case-insensitive), optionally
     * excluding a set of addresses (e.g. addresses already present in To).
     */
    private function dedupeFields( array $fields, array $exclude_addresses = [] ): array {
        $seen = array_map( 'strtolower', $exclude_addresses );
        $out  = [];

        foreach ( $fields as $field ) {
            $addr = strtolower( $field['address'] ?? '' );
            if ( $addr === '' || in_array( $addr, $seen, true ) ) {
                continue;
            }
            $seen[] = $addr;
            $out[]  = $field;
        }

        return $out;
    }

    /**
     * The current user's own email addresses, used to exclude self when
     * building reply-all recipients. Seeded from the --from address and the
     * optional MISSIVE_MY_ADDRESSES constant/env (comma-separated).
     */
    private function myAddresses( string $from_address ): array {
        $addresses = [];
        if ( $from_address !== '' ) {
            $addresses[] = strtolower( $from_address );
        }

        $extra = null;
        if ( defined( 'MISSIVE_MY_ADDRESSES' ) && MISSIVE_MY_ADDRESSES ) {
            $extra = MISSIVE_MY_ADDRESSES;
        } else {
            $env = getenv( 'MISSIVE_MY_ADDRESSES' );
            if ( $env ) {
                $extra = $env;
            }
        }
        if ( $extra ) {
            foreach ( explode( ',', $extra ) as $addr ) {
                $addr = strtolower( trim( $addr ) );
                if ( $addr !== '' ) {
                    $addresses[] = $addr;
                }
            }
        }

        return array_values( array_unique( $addresses ) );
    }

    /**
     * Build reply-all To/Cc lists from the latest inbound message in a
     * conversation. Replies to the most recent message not sent by the current
     * user (falling back to the most recent message). The original sender plus
     * its To recipients become To; its Cc recipients become Cc. The current
     * user's own addresses are excluded throughout.
     *
     * @return array{to: array, cc: array}
     */
    private function buildReplyAllRecipients( string $conv_id, string $from_address ): array {
        $response = Missive::get( "/conversations/{$conv_id}/messages" );
        $messages = $response['messages'] ?? [];

        if ( empty( $messages ) ) {
            \WP_CLI::error( "No messages found in conversation to reply to." );
        }

        $mine = $this->myAddresses( $from_address );

        // Messages are newest-first; prefer the most recent one not from me.
        $target = null;
        foreach ( $messages as $message ) {
            $addr = strtolower( $message['from_field']['address'] ?? '' );
            if ( $addr !== '' && ! in_array( $addr, $mine, true ) ) {
                $target = $message;
                break;
            }
        }
        if ( $target === null ) {
            $target = $messages[0];
        }

        $to   = [];
        $cc   = [];
        $seen = $mine;

        $add = function( array $field, array &$bucket ) use ( &$seen ) {
            $addr = strtolower( $field['address'] ?? '' );
            if ( $addr === '' || in_array( $addr, $seen, true ) ) {
                return;
            }
            $seen[]  = $addr;
            $entry   = [ 'address' => $field['address'] ];
            if ( ! empty( $field['name'] ) ) {
                $entry['name'] = $field['name'];
            }
            $bucket[] = $entry;
        };

        if ( ! empty( $target['from_field'] ) ) {
            $add( $target['from_field'], $to );
        }
        foreach ( $target['to_fields'] ?? [] as $field ) {
            $add( $field, $to );
        }
        foreach ( $target['cc_fields'] ?? [] as $field ) {
            $add( $field, $cc );
        }

        return [ 'to' => $to, 'cc' => $cc ];
    }

    /**
     * Create an email draft in Missive
     *
     * ## OPTIONS
     *
     * [--to=<email>]
     * : Recipient email address(es), comma-separated (e.g., "user@example.com" or "Name <user@example.com>"). Required unless --reply-all is used.
     *
     * [--reply-all]
     * : Auto-populate To and Cc from the latest inbound message in the conversation (requires --conversation). Your own address (--from and MISSIVE_MY_ADDRESSES) is excluded. Explicit --to/--cc still take precedence/merge.
     *
     * [--subject=<subject>]
     * : Email subject line (required for new conversations)
     *
     * [--body=<body>]
     * : Email body (HTML or plain text)
     *
     * [--body-file=<path>]
     * : Path to file containing email body (alternative to --body)
     *
     * [--conversation=<id>]
     * : Conversation ID to reply to (supports partial ID matching)
     *
     * [--from=<email>]
     * : Sender email address (must match a Missive alias)
     *
     * [--cc=<emails>]
     * : CC recipients (comma-separated)
     *
     * [--bcc=<emails>]
     * : BCC recipients (comma-separated)
     *
     * [--send]
     * : Send immediately instead of creating a draft
     *
     * ## EXAMPLES
     *
     *     # Reply to conversation
     *     wp missive draft --to="user@example.com" --body="Thanks!" --conversation=abc123
     *
     *     # New email with subject
     *     wp missive draft --to="user@example.com" --subject="Hello" --body="Message"
     *
     *     # Body from file
     *     wp missive draft --to="user@example.com" --subject="Report" --body-file=./email.html
     *
     *     # Send immediately
     *     wp missive draft --to="user@example.com" --subject="Urgent" --body="Message" --send
     *
     *     # Reply-all on a conversation (To/Cc auto-filled from the latest inbound message)
     *     wp missive draft --reply-all --conversation=abc123 --from="austin@anchor.host" --body="Thanks!"
     *
     * @when after_wp_load
     */
    public function draft( $args, $assoc_args ) {
        // Get body from --body or --body-file
        if ( isset( $assoc_args['body'] ) ) {
            $body = $assoc_args['body'];
        } elseif ( isset( $assoc_args['body-file'] ) ) {
            $file_path = $assoc_args['body-file'];
            if ( ! file_exists( $file_path ) ) {
                \WP_CLI::error( "File not found: $file_path" );
            }
            $body = file_get_contents( $file_path );
            if ( $body === false ) {
                \WP_CLI::error( "Could not read file: $file_path" );
            }
        } else {
            \WP_CLI::error( "Must provide --body or --body-file" );
        }

        // Resolve sender (used to exclude self from reply-all recipients)
        $from_field   = isset( $assoc_args['from'] ) ? $this->parseEmailAddress( $assoc_args['from'] ) : null;
        $from_address = $from_field['address'] ?? '';

        // Resolve conversation ID early (needed for reply-all), with partial matching
        $conv_id = null;
        if ( isset( $assoc_args['conversation'] ) ) {
            $conv_id = $assoc_args['conversation'];
            if ( strlen( $conv_id ) < 36 ) {
                $full_id = $this->getDb()->findByPartialId( $conv_id );
                if ( $full_id ) {
                    $conv_id = $full_id;
                }
            }
        }

        // Reply-all: derive To/Cc from the latest inbound message in the thread
        $reply_to = [];
        $reply_cc = [];
        if ( isset( $assoc_args['reply-all'] ) ) {
            if ( ! $conv_id ) {
                \WP_CLI::error( "--reply-all requires --conversation." );
            }
            $recipients = $this->buildReplyAllRecipients( $conv_id, $from_address );
            $reply_to = $recipients['to'];
            $reply_cc = $recipients['cc'];
        }

        // Build recipient lists: explicit flags take precedence, then reply-all defaults
        $to_fields = ! empty( $assoc_args['to'] ) ? $this->parseEmailList( $assoc_args['to'] ) : $reply_to;

        if ( empty( $to_fields ) ) {
            \WP_CLI::error( "The --to parameter is required (or use --reply-all on a conversation that has recipients)." );
        }

        $cc_fields = $reply_cc;
        if ( isset( $assoc_args['cc'] ) ) {
            $cc_fields = array_merge( $cc_fields, $this->parseEmailList( $assoc_args['cc'] ) );
        }

        // Dedupe: unique To, and Cc minus anyone already in To
        $to_fields    = $this->dedupeFields( $to_fields );
        $to_addresses = array_map( fn( $f ) => strtolower( $f['address'] ?? '' ), $to_fields );
        $cc_fields    = $this->dedupeFields( $cc_fields, $to_addresses );

        // Build draft payload
        $draft = [
            'to_fields' => $to_fields,
            'body'      => $body,
        ];

        // Optional subject
        if ( isset( $assoc_args['subject'] ) ) {
            $draft['subject'] = $assoc_args['subject'];
        }

        // Optional from
        if ( $from_field ) {
            $draft['from_field'] = $from_field;
        }

        // CC (explicit and/or reply-all)
        if ( ! empty( $cc_fields ) ) {
            $draft['cc_fields'] = $cc_fields;
        }

        // Optional BCC
        if ( isset( $assoc_args['bcc'] ) ) {
            $draft['bcc_fields'] = $this->parseEmailList( $assoc_args['bcc'] );
        }

        // Optional conversation
        if ( $conv_id ) {
            $draft['conversation'] = $conv_id;
        }

        // Optional send flag
        if ( isset( $assoc_args['send'] ) ) {
            $draft['send'] = true;
        }

        // Surface resolved recipients when reply-all filled them in
        if ( isset( $assoc_args['reply-all'] ) ) {
            \WP_CLI::log( "Reply-all To: " . $this->formatFieldList( $to_fields ) );
            if ( ! empty( $cc_fields ) ) {
                \WP_CLI::log( "Reply-all Cc: " . $this->formatFieldList( $cc_fields ) );
            }
        }

        $payload = [ 'drafts' => $draft ];

        try {
            $response = Missive::post( '/drafts', $payload );

            $action = isset( $assoc_args['send'] ) ? 'Sent' : 'Draft created';
            $draft_id = $response['drafts']['id'] ?? 'unknown';
            $conv_id = $response['drafts']['conversation'] ?? '';

            \WP_CLI::success( "$action successfully (ID: $draft_id)" );

            if ( $conv_id ) {
                \WP_CLI::log( "Conversation: https://mail.missiveapp.com/#inbox/conversations/$conv_id" );
            }
        } catch ( \Exception $e ) {
            \WP_CLI::error( "API error: " . $e->getMessage() );
        }
    }

    /**
     * List drafts in a conversation
     *
     * ## OPTIONS
     *
     * <id>
     * : Conversation ID (supports partial matching)
     *
     * ## EXAMPLES
     *
     *     wp missive drafts abc123
     *     wp missive drafts 4efe2a89-bf8d-4e60-8874-cc314942521c
     *
     * @when after_wp_load
     */
    public function drafts( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( "Usage: wp missive drafts <conversation_id>" );
        }

        $id = $args[0];

        // Support partial ID matching
        if ( strlen( $id ) < 36 ) {
            $db = $this->getDb();
            $full_id = $db->findByPartialId( $id );
            if ( $full_id ) {
                $id = $full_id;
            }
        }

        try {
            $response = Missive::get( "/conversations/$id/drafts" );
            $drafts = $response['drafts'] ?? [];

            if ( empty( $drafts ) ) {
                \WP_CLI::log( "No drafts found for this conversation." );
                return;
            }

            $rows = [];
            foreach ( $drafts as $draft ) {
                $to = '';
                if ( ! empty( $draft['to_fields'] ) ) {
                    $first = $draft['to_fields'][0];
                    $to = $first['name'] ?? $first['address'] ?? '';
                }

                $rows[] = [
                    'ID'      => $draft['id'] ?? 'unknown',
                    'Subject' => mb_substr( $draft['subject'] ?? '(no subject)', 0, 50 ),
                    'To'      => mb_substr( $to, 0, 30 ),
                    'Date'    => isset( $draft['delivered_at'] ) ? date( 'Y-m-d H:i', $draft['delivered_at'] ) : '',
                ];
            }

            \WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'Subject', 'To', 'Date' ] );

        } catch ( \Exception $e ) {
            \WP_CLI::error( "API error: " . $e->getMessage() );
        }
    }

    /**
     * Delete a draft
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more draft IDs to delete
     *
     * ## EXAMPLES
     *
     *     wp missive delete-draft 9a7f9966-0483-430f-81b7-e2ebe928f455
     *     wp missive delete-draft id1 id2 id3
     *
     * @when after_wp_load
     */
    public function delete_draft( $args, $assoc_args ) {
        if ( empty( $args ) ) {
            \WP_CLI::error( "Usage: wp missive delete-draft <draft_id> [<draft_id>...]" );
        }

        foreach ( $args as $draft_id ) {
            try {
                Missive::delete( "/drafts/$draft_id" );
                \WP_CLI::success( "Deleted draft: $draft_id" );
            } catch ( \Exception $e ) {
                \WP_CLI::warning( "Failed to delete $draft_id: " . $e->getMessage() );
            }
        }
    }

    /**
     * Close a conversation
     *
     * Closes the conversation both in Missive and in the local database.
     * Accepts IDs as arguments or piped via stdin.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : One or more conversation IDs to close (supports partial matching)
     *
     * [--username=<username>]
     * : Display name for the close action (defaults to MISSIVE_API_NAME constant)
     *
     * [--local]
     * : Only close in the local database, skip the Missive API
     *
     * ## EXAMPLES
     *
     *     wp missive close 32891480
     *     wp missive close 32891480 68c15b55
     *     wp missive close --local 32891480
     *     wp missive search "Injection" --format=ids | xargs wp missive close
     *
     * @when after_wp_load
     */
    public function close( $args, $assoc_args ) {
        // Read from stdin if no args and stdin is piped
        if ( empty( $args ) && ! posix_isatty( STDIN ) ) {
            $stdin = stream_get_contents( STDIN );
            $args = array_filter( array_map( 'trim', explode( "\n", $stdin ) ) );
        }

        if ( empty( $args ) ) {
            \WP_CLI::error( "Usage: wp missive close <id> [<id>...]" );
        }

        $db = $this->getDb();
        $total = count( $args );
        $quiet = $total > 10;

        // Resolve all IDs first
        $ids = [];
        $not_found = 0;
        foreach ( $args as $input_id ) {
            $id = $input_id;
            if ( strlen( $id ) < 36 ) {
                $full_id = $db->findByPartialId( $id );
                if ( $full_id ) {
                    $id = $full_id;
                } else {
                    \WP_CLI::warning( "Conversation not found: $input_id" );
                    $not_found++;
                    continue;
                }
            }
            $ids[] = $id;
        }

        if ( empty( $ids ) ) {
            \WP_CLI::error( "No valid conversations to close." );
        }

        $local_only = isset( $assoc_args['local'] );
        $username = $assoc_args['username'] ?? ( defined( 'MISSIVE_API_NAME' ) ? MISSIVE_API_NAME : 'Missive CLI' );
        $closed = 0;
        $failed = 0;

        foreach ( $ids as $id ) {
            // Close via Missive API unless --local flag is set
            if ( ! $local_only ) {
                try {
                    Missive::post( '/posts', [
                        'posts' => [
                            'conversation'  => $id,
                            'close'         => true,
                            'reopen'        => false,
                            'notification'  => [ 'title' => 'Closed', 'body' => 'Conversation closed' ],
                            'markdown'      => 'Conversation closed.',
                            'username'      => $username,
                        ],
                    ] );
                } catch ( \Exception $e ) {
                    \WP_CLI::warning( "API error closing $id: " . $e->getMessage() );
                    $failed++;
                    continue;
                }
            }

            // Update local database
            $db->closeConversation( $id );
            $closed++;

            if ( ! $quiet ) {
                $conv = $db->getConversation( $id );
                $subject = $conv['subject'] ?? '(no subject)';
                \WP_CLI::success( "Closed: $subject ($id)" );
            }
        }

        $msg = "Closed $closed conversation" . ( $closed !== 1 ? 's' : '' );
        if ( $failed > 0 ) {
            $msg .= " ($failed failed)";
        }
        if ( $not_found > 0 ) {
            $msg .= " ($not_found not found)";
        }
        \WP_CLI::success( $msg . '.' );
    }

    /**
     * List comments on a conversation
     *
     * Fetches comments from the Missive API for a given conversation.
     *
     * ## OPTIONS
     *
     * <id>
     * : Conversation ID (supports partial matching)
     *
     * [--limit=<number>]
     * : Number of comments to fetch per page (max 10)
     * ---
     * default: 10
     * ---
     *
     * [--all]
     * : Paginate through all comments
     *
     * ## EXAMPLES
     *
     *     wp missive comments abc123
     *     wp missive comments abc123 --all
     *
     * @when after_wp_load
     */
    public function comments( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( "Usage: wp missive comments <conversation_id>" );
        }

        $id = $args[0];
        $limit = (int) ( $assoc_args['limit'] ?? 10 );
        $fetch_all = isset( $assoc_args['all'] );

        // Support partial ID matching
        if ( strlen( $id ) < 36 ) {
            $db = $this->getDb();
            $full_id = $db->findByPartialId( $id );
            if ( $full_id ) {
                $id = $full_id;
            }
        }

        $all_comments = [];
        $until = null;

        do {
            $params = [ 'limit' => $limit ];
            if ( $until ) {
                $params['until'] = $until;
            }

            try {
                $response = Missive::get( "/conversations/$id/comments", $params );
            } catch ( \Exception $e ) {
                \WP_CLI::error( "API error: " . $e->getMessage() );
            }

            $comments = $response['comments'] ?? [];
            if ( empty( $comments ) ) {
                break;
            }

            $all_comments = array_merge( $all_comments, $comments );

            // Stop if we got fewer than limit (last page)
            if ( count( $comments ) < $limit ) {
                break;
            }

            // Use oldest comment's created_at for pagination
            $until = end( $comments )['created_at'] ?? null;

        } while ( $fetch_all && $until );

        if ( empty( $all_comments ) ) {
            \WP_CLI::log( "No comments found for this conversation." );
            return;
        }

        // Display oldest first
        $all_comments = array_reverse( $all_comments );

        \WP_CLI::log( "=== Comments (" . count( $all_comments ) . ") ===" );

        foreach ( $all_comments as $comment ) {
            $author = $comment['author']['name'] ?? $comment['author']['email'] ?? 'Unknown';
            $date = isset( $comment['created_at'] ) ? date( 'Y-m-d H:i', $comment['created_at'] ) : '';
            $body = $comment['body'] ?? '';

            // Show task info if present
            $task_info = '';
            if ( ! empty( $comment['task'] ) ) {
                $task = $comment['task'];
                $task_info = ' [Task: ' . ( $task['description'] ?? '' ) . ' (' . ( $task['state'] ?? '' ) . ')]';
            }

            \WP_CLI::log( "\n$author ($date)$task_info" );
            \WP_CLI::log( $body );
        }
    }

    /**
     * Search conversations by keyword
     *
     * Searches subjects, message bodies, or authors in the local database.
     *
     * ## OPTIONS
     *
     * <term>
     * : Search term (substring match)
     *
     * [--field=<field>]
     * : Field to search (subject, body, from)
     * ---
     * default: subject
     * ---
     *
     * [--status=<status>]
     * : Filter by status (open or closed)
     *
     * [--before=<date>]
     * : Only show conversations with activity before this date (YYYY-MM-DD)
     *
     * [--after=<date>]
     * : Only show conversations with activity after this date (YYYY-MM-DD)
     *
     * [--limit=<number>]
     * : Limit results
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : Output format (table, ids, or count)
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp missive search "Site Removal"
     *     wp missive search "Site Removal" --status=open
     *     wp missive search "kinsta" --field=body
     *     wp missive search "launchkits" --field=from
     *     wp missive search "Injection detected" --format=ids
     *     wp missive search "Monitor:" --status=open --before=2026-02-14
     *     wp missive search "Monitor:" --status=open --after=2026-03-01 --format=count
     *
     * @when after_wp_load
     */
    public function search( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( "Usage: wp missive search <term>" );
        }

        $term = $args[0];
        $db = $this->getDb();

        $filters = [
            'field'  => $assoc_args['field'] ?? 'subject',
            'status' => $assoc_args['status'] ?? null,
            'before' => isset( $assoc_args['before'] ) ? strtotime( $assoc_args['before'] . ' 23:59:59' ) : null,
            'after'  => isset( $assoc_args['after'] )  ? strtotime( $assoc_args['after'] . ' 00:00:00' )  : null,
            'limit'  => (int) ( $assoc_args['limit'] ?? 50 ),
        ];

        $conversations = $db->searchConversations( $term, $filters );

        $format = $assoc_args['format'] ?? 'table';

        if ( empty( $conversations ) ) {
            if ( $format === 'ids' || $format === 'count' ) {
                if ( $format === 'count' ) {
                    echo "0\n";
                }
                return;
            }
            \WP_CLI::log( "No conversations found matching \"$term\"." );
            return;
        }

        if ( $format === 'count' ) {
            \WP_CLI::log( count( $conversations ) );
            return;
        }

        if ( $format === 'ids' ) {
            foreach ( $conversations as $conv ) {
                echo substr( $conv['id'], 0, 8 ) . "\n";
            }
            return;
        }

        $rows = [];
        foreach ( $conversations as $conv ) {
            $authors = json_decode( $conv['authors'], true ) ?: [];
            $author_str = '';
            if ( ! empty( $authors ) ) {
                $first_author = $authors[0];
                $author_str = $first_author['name'] ?? $first_author['address'] ?? '';
            }

            $subject = $conv['subject'] ?: $conv['message_subject'] ?? '(no subject)';

            $rows[] = [
                'ID'       => substr( $conv['id'], 0, 8 ) . '...',
                'Subject'  => mb_substr( $subject, 0, 50 ),
                'From'     => mb_substr( $author_str, 0, 25 ),
                'Activity' => date( 'Y-m-d H:i', $conv['last_activity_at'] ),
                'Status'   => $conv['status'] ?? 'open',
            ];
        }

        \WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'Subject', 'From', 'Activity', 'Status' ] );
    }

    /**
     * Show Missive API endpoint reference
     *
     * Displays available Missive REST API endpoints grouped by resource.
     * Use with `wp missive api` to query these endpoints directly.
     *
     * ## OPTIONS
     *
     * [<section>]
     * : Show only a specific section (e.g., conversations, drafts, messages)
     *
     * ## EXAMPLES
     *
     *     wp missive endpoints
     *     wp missive endpoints conversations
     *     wp missive endpoints drafts
     *
     * @when after_wp_load
     */
    public function endpoints( $args, $assoc_args ) {
        $section_filter = ! empty( $args[0] ) ? strtolower( $args[0] ) : null;

        $sections = [
            'conversations' => [
                'GET  /conversations'              => 'List conversations (params: inbox, all, closed, shared_label, team_inbox, email, domain, limit, until)',
                'GET  /conversations/:id'          => 'Get a single conversation',
                'GET  /conversations/:id/messages'  => 'List messages in a conversation',
                'GET  /conversations/:id/comments'  => 'List comments in a conversation',
                'GET  /conversations/:id/drafts'    => 'List drafts in a conversation',
                'GET  /conversations/:id/posts'     => 'List posts in a conversation',
                'POST /conversations/:id/merge'     => 'Merge into another conversation (data: target, subject)',
            ],
            'messages' => [
                'GET  /messages/:id'                    => 'Get message with headers and body (supports comma-separated IDs)',
                'GET  /messages?email_message_id=<mid>' => 'Find messages by email Message-ID header',
                'POST /messages'                        => 'Create incoming message in custom channel',
            ],
            'drafts' => [
                'POST   /drafts'     => 'Create draft or send email (send: true). Params: subject, body, from_field, to_fields, conversation, send, send_at, close, add_shared_labels',
                'DELETE /drafts/:id' => 'Delete a draft',
            ],
            'posts' => [
                'POST   /posts'     => 'Create post / close / reopen / assign / label a conversation. Params: conversation, text, close, reopen, add_shared_labels, remove_shared_labels, add_assignees, username',
                'DELETE /posts/:id' => 'Delete a post',
            ],
            'contacts' => [
                'GET   /contacts'            => 'List/search contacts (params: contact_book, search, modified_since, limit, offset)',
                'GET   /contacts/:id'        => 'Get a single contact',
                'POST  /contacts'            => 'Create contacts',
                'PATCH /contacts/:id1,:id2'  => 'Update contacts',
            ],
            'shared_labels' => [
                'GET   /shared_labels'           => 'List shared labels (params: organization)',
                'POST  /shared_labels'           => 'Create shared labels',
                'PATCH /shared_labels/:id1,:id2' => 'Update shared labels',
            ],
            'tasks' => [
                'GET   /tasks'     => 'List tasks (params: organization, team, assignee, state, conversation, limit, until)',
                'GET   /tasks/:id' => 'Get a single task',
                'POST  /tasks'     => 'Create a task',
                'PATCH /tasks/:id' => 'Update a task (title, description, state, assignees, due_at)',
            ],
            'teams' => [
                'GET   /teams'           => 'List teams',
                'POST  /teams'           => 'Create teams (admin only)',
                'PATCH /teams/:id1,:id2' => 'Update teams (admin only)',
            ],
            'users' => [
                'GET /users'    => 'List users in your organizations',
                'GET /users/me' => 'Get current authenticated user',
            ],
            'hooks' => [
                'POST   /hooks'     => 'Create webhook (params: type, url, organization, content_contains, from_eq, subject_contains)',
                'DELETE /hooks/:id' => 'Delete webhook',
            ],
            'other' => [
                'GET /organizations'  => 'List your organizations',
                'GET /contact_books'  => 'List contact books',
                'GET /contact_groups' => 'List contact groups (params: contact_book, kind)',
                'GET /responses'      => 'List canned responses',
                'GET /responses/:id'  => 'Get a canned response',
            ],
        ];

        foreach ( $sections as $name => $endpoints ) {
            if ( $section_filter && $section_filter !== $name ) {
                continue;
            }

            \WP_CLI::log( "\n  \033[1m" . strtoupper( $name ) . "\033[0m" );
            foreach ( $endpoints as $endpoint => $desc ) {
                \WP_CLI::log( "    \033[32m$endpoint\033[0m" );
                \WP_CLI::log( "      $desc" );
            }
        }

        if ( $section_filter && ! isset( $sections[ $section_filter ] ) ) {
            $available = implode( ', ', array_keys( $sections ) );
            \WP_CLI::warning( "Unknown section \"$section_filter\". Available: $available" );
        }

        \WP_CLI::log( "" );
        \WP_CLI::log( "  \033[90mUsage: wp missive api <endpoint> [--method=<method>] [--data=<json>]\033[0m" );
        \WP_CLI::log( "  \033[90mNote: Conversation actions (close, assign, label) use the /posts endpoint.\033[0m" );
        \WP_CLI::log( "" );
    }

    /**
     * Show database statistics
     *
     * ## EXAMPLES
     *
     *     wp missive stats
     *
     * @when after_wp_load
     */
    public function stats( $args, $assoc_args ) {
        $db = $this->getDb();
        $stats = $db->getStats();

        \WP_CLI::log( "=== Database Statistics ===" );
        \WP_CLI::log( "Database: " . $db->getPath() );
        \WP_CLI::log( "Conversations: " . $stats['conversations'] );
        \WP_CLI::log( "  Open: " . $stats['open_conversations'] );
        \WP_CLI::log( "  Closed: " . ( $stats['conversations'] - $stats['open_conversations'] ) );
        \WP_CLI::log( "Messages: " . $stats['messages'] );
        if ( $stats['oldest_message'] ) {
            \WP_CLI::log( "  Oldest: " . date( 'Y-m-d H:i', $stats['oldest_message'] ) );
        }
        if ( $stats['newest_message'] ) {
            \WP_CLI::log( "  Newest: " . date( 'Y-m-d H:i', $stats['newest_message'] ) );
        }
        \WP_CLI::log( "Classified: " . $stats['classified'] . " / " . $stats['conversations'] );

        $unclassified = $stats['conversations'] - $stats['classified'];
        if ( $unclassified > 0 ) {
            \WP_CLI::log( "Unclassified: $unclassified" );
        }
    }
}
