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
     * : Re-fetch all message bodies even if unchanged
     *
     * ## EXAMPLES
     *
     *     wp missive sync
     *     wp missive sync --timeframe=1d
     *     wp missive sync --timeframe=7d --full
     *     wp missive sync --timeframe=12h --force
     *     wp missive sync --all-open
     *
     * @when after_wp_load
     */
    public function sync( $args, $assoc_args ) {
        $timeframe = $assoc_args['timeframe'] ?? '1w';
        $force    = isset( $assoc_args['force'] );
        $full     = isset( $assoc_args['full'] );
        $all_open = isset( $assoc_args['all-open'] );

        if ( $all_open ) {
            $since = 0;
            \WP_CLI::log( "Syncing all open conversations" . ( $force ? ' (force refresh)' : '' ) . "..." );
        } else {
            $duration_seconds = $this->parseDuration( $timeframe );
            $since = time() - $duration_seconds;
            $human_duration = $this->formatDuration( $duration_seconds );
            $scope = $full ? 'open + closed' : 'open';
            \WP_CLI::log( "Syncing $scope conversations from the last $human_duration" . ( $force ? ' (force refresh)' : '' ) . "..." );
            \WP_CLI::log( "Cutoff: " . date( 'Y-m-d H:i:s', $since ) );
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
                list( $ids, $msg_count ) = $this->syncInbox( $inbox_params, $since, $force );
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
                    list( $ids, $msg_count ) = $this->syncInbox( $closed_params, $since, $force, 'closed' );
                    $synced_ids = array_merge( $synced_ids, $ids );
                    $total_messages += $msg_count;
                } catch ( \Exception $e ) {
                    \WP_CLI::warning( ucfirst( $inbox_label ) . " (closed) error: " . $e->getMessage() );
                }
            }
        }

        // Mark conversations not in synced list as closed
        $synced_ids = array_unique( $synced_ids );
        $closed_count = $db->markClosedExcept( $synced_ids );

        \WP_CLI::success( sprintf(
            "Synced %d conversations (%d messages). Marked %d as closed.",
            count( $synced_ids ),
            $total_messages,
            $closed_count
        ) );
    }

    /**
     * Sync an inbox and return list of synced conversation IDs
     */
    private function syncInbox( array $options, int $since, bool $force = false, string $status = 'open' ): array {
        $db = $this->getDb();
        $synced_ids = [];
        $messages_synced = 0;
        $until = null;

        do {
            $params = array_merge( $options, [ 'limit' => 50 ] );
            if ( $until ) {
                $params['until'] = $until;
            }

            $response = Missive::get( '/conversations', $params );
            $conversations = $response['conversations'] ?? [];

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
                $messages = [];

                // Check if conversation has new activity (or force refresh)
                $needs_sync = $force || $db->needsMessageSync( $conv['id'], $activity_time );

                // Always update conversation metadata
                $db->upsertConversation( $conv, $status );

                if ( $needs_sync ) {
                    try {
                        $msg_response = Missive::get( "/conversations/{$conv['id']}/messages" );
                        $messages = $msg_response['messages'] ?? [];

                        // Collect message IDs that need full body fetch
                        $ids_to_fetch = [];
                        foreach ( $messages as $msg ) {
                            if ( ( $force || empty( $msg['body'] ) ) && ! empty( $msg['id'] ) ) {
                                $ids_to_fetch[] = $msg['id'];
                            }
                        }

                        // Batch fetch full messages (up to 50 per request)
                        $full_messages = [];
                        foreach ( array_chunk( $ids_to_fetch, 50 ) as $batch ) {
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
                                \WP_CLI::warning( "Batch fetch failed, falling back to individual: " . $e->getMessage() );
                                foreach ( $batch as $msg_id ) {
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

                        // Merge full bodies and upsert all messages
                        foreach ( $messages as $msg ) {
                            if ( ! empty( $msg['id'] ) && isset( $full_messages[ $msg['id'] ] ) ) {
                                $msg = array_merge( $msg, $full_messages[ $msg['id'] ] );
                            }
                            $db->upsertMessage( $msg, $conv['id'] );
                            $messages_synced++;
                        }
                    } catch ( \Exception $e ) {
                        \WP_CLI::warning( "Could not fetch messages for {$conv['id']}: " . $e->getMessage() );
                    }

                    // Build display: subject or first author
                    $display = $conv['subject'] ?? '';
                    if ( ! $display && ! empty( $messages ) ) {
                        $display = $messages[0]['subject'] ?? '';
                    }
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
     * [--unclassified]
     * : Show only unclassified conversations
     *
     * [--preview]
     * : Show a preview snippet of the latest message
     *
     * [--format=<format>]
     * : Output format (table or ids)
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

        $filters['limit'] = (int) ( $assoc_args['limit'] ?? 50 );

        $db = $this->getDb();
        $conversations = $db->getConversations( $filters );

        if ( empty( $conversations ) ) {
            \WP_CLI::log( "No conversations found." );
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        if ( $format === 'ids' ) {
            foreach ( $conversations as $conv ) {
                echo substr( $conv['id'], 0, 8 ) . "\n";
            }
            return;
        }

        $show_preview = isset( $assoc_args['preview'] );

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
                'ID'         => substr( $conv['id'], 0, 8 ) . '...',
                'Subject'    => mb_substr( $subject, 0, 50 ),
                'From'       => mb_substr( $author_str, 0, 25 ),
                'Activity'   => date( 'Y-m-d H:i', $conv['last_activity_at'] ),
                'Status'     => $conv['status'] ?? 'open',
                'Classified' => $conv['has_classification'] > 0 ? 'Yes' : 'No',
            ];

            if ( $show_preview ) {
                $preview = $conv['latest_preview'] ?? '';
                $row['Preview'] = mb_substr( $preview, 0, 80 );
            }

            $rows[] = $row;
        }

        $columns = [ 'ID', 'Subject', 'From', 'Activity', 'Status', 'Classified' ];
        if ( $show_preview ) {
            $columns[] = 'Preview';
        }

        \WP_CLI\Utils\format_items( 'table', $rows, $columns );
    }

    /**
     * Show conversation details
     *
     * ## OPTIONS
     *
     * <id>
     * : Conversation ID (supports partial matching)
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
     *     wp missive show abc123 --full
     *     wp missive show abc123 --pretty
     *     wp missive show abc123 --links
     *     wp missive show abc123 --format=json
     *
     * @when after_wp_load
     */
    public function show( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( "Usage: wp missive show <conversation_id>" );
        }

        $id = $args[0];
        $db = $this->getDb();

        // Support partial ID matching
        if ( strlen( $id ) < 36 ) {
            $full_id = $db->findByPartialId( $id );
            if ( $full_id ) {
                $id = $full_id;
            }
        }

        $conv = $db->getConversation( $id );

        if ( ! $conv ) {
            \WP_CLI::error( "Conversation not found: $id" );
        }

        $format = $assoc_args['format'] ?? 'text';
        $pretty = isset( $assoc_args['pretty'] );
        $full = isset( $assoc_args['full'] ) || $format === 'json' || $pretty;

        // Links extraction mode
        if ( isset( $assoc_args['links'] ) ) {
            $urls = [];
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
            $output = [
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
                        'subject'      => $msg['subject'] ?? null,
                        'preview'      => $msg['preview'] ?? null,
                        'body'         => $msg['body'] ?? null,
                        'delivered_at' => $msg['delivered_at'],
                    ];
                }, $conv['messages'] ),
            ];
            echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
            return;
        }

        // Pretty TUI output
        if ( $pretty ) {
            $this->showPretty( $conv );
            return;
        }

        // Text output
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
     * Create an email draft in Missive
     *
     * ## OPTIONS
     *
     * --to=<email>
     * : Recipient email address (e.g., "user@example.com" or "Name <user@example.com>")
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
     * @when after_wp_load
     */
    public function draft( $args, $assoc_args ) {
        // Validate --to is provided
        if ( empty( $assoc_args['to'] ) ) {
            \WP_CLI::error( "The --to parameter is required." );
        }

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

        // Build draft payload
        $draft = [
            'to_fields' => [ $this->parseEmailAddress( $assoc_args['to'] ) ],
            'body'      => $body,
        ];

        // Optional subject
        if ( isset( $assoc_args['subject'] ) ) {
            $draft['subject'] = $assoc_args['subject'];
        }

        // Optional from
        if ( isset( $assoc_args['from'] ) ) {
            $draft['from_field'] = $this->parseEmailAddress( $assoc_args['from'] );
        }

        // Optional CC
        if ( isset( $assoc_args['cc'] ) ) {
            $draft['cc_fields'] = $this->parseEmailList( $assoc_args['cc'] );
        }

        // Optional BCC
        if ( isset( $assoc_args['bcc'] ) ) {
            $draft['bcc_fields'] = $this->parseEmailList( $assoc_args['bcc'] );
        }

        // Optional conversation ID (with partial matching support)
        if ( isset( $assoc_args['conversation'] ) ) {
            $conv_id = $assoc_args['conversation'];

            // Support partial ID matching
            if ( strlen( $conv_id ) < 36 ) {
                $db = $this->getDb();
                $full_id = $db->findByPartialId( $conv_id );
                if ( $full_id ) {
                    $conv_id = $full_id;
                }
            }

            $draft['conversation'] = $conv_id;
        }

        // Optional send flag
        if ( isset( $assoc_args['send'] ) ) {
            $draft['send'] = true;
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
     * ## EXAMPLES
     *
     *     wp missive close 32891480
     *     wp missive close 32891480 68c15b55
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
        $closed = 0;
        $failed = 0;
        $total  = count( $args );
        $quiet  = $total > 10;

        foreach ( $args as $input_id ) {
            $id = $input_id;

            // Support partial ID matching
            if ( strlen( $id ) < 36 ) {
                $full_id = $db->findByPartialId( $id );
                if ( $full_id ) {
                    $id = $full_id;
                } else {
                    \WP_CLI::warning( "Conversation not found: $input_id" );
                    $failed++;
                    continue;
                }
            }

            try {
                Missive::post( '/posts', [
                    'posts' => [
                        'conversation' => $id,
                        'close'        => true,
                        'text'         => 'Conversation closed via CLI.',
                        'notification' => [
                            'title' => 'Conversation closed',
                            'body'  => 'Closed via CLI',
                        ],
                        'username'     => $assoc_args['username'] ?? ( defined( 'MISSIVE_API_NAME' ) ? MISSIVE_API_NAME : 'Missive CLI' ),
                    ],
                ] );
            } catch ( \Exception $e ) {
                \WP_CLI::warning( "API error closing $input_id: " . $e->getMessage() );
                $failed++;
                continue;
            }

            // Update local database
            $db->closeConversation( $id );

            $conv = $db->getConversation( $id );
            $subject = $conv['subject'] ?? '(no subject)';

            if ( $quiet ) {
                $closed++;
            } else {
                \WP_CLI::success( "Closed: $subject ($input_id)" );
                $closed++;
            }
        }

        if ( $quiet ) {
            $msg = "Closed $closed conversation" . ( $closed !== 1 ? 's' : '' );
            if ( $failed > 0 ) {
                $msg .= " ($failed failed)";
            }
            \WP_CLI::success( $msg . '.' );
        }
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
