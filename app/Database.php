<?php
/**
 * SQLite storage layer for Missive conversations
 */

namespace MissiveCLI;

class Database {

    private \PDO $db;
    private string $db_path;

    /**
     * Parse a timestamp that may be either a Unix timestamp or date string
     */
    private static function parseTimestamp( mixed $value ): ?int {
        if ( $value === null ) {
            return null;
        }
        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
            return (int) $value;
        }
        if ( is_string( $value ) ) {
            $parsed = strtotime( $value );
            return $parsed !== false ? $parsed : null;
        }
        return null;
    }

    public function __construct( ?string $db_path = null ) {
        $this->db_path = $db_path ?? ABSPATH . '../private/missive.db';
        $this->db = new \PDO( "sqlite:{$this->db_path}" );
        $this->db->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
        $this->initSchema();
    }

    /**
     * Get the database file path
     */
    public function getPath(): string {
        return $this->db_path;
    }

    /**
     * Initialize database schema
     */
    private function initSchema(): void {
        // Run migrations first for existing databases
        $this->migrateSchema();

        $this->db->exec( "
            CREATE TABLE IF NOT EXISTS conversations (
                id TEXT PRIMARY KEY,
                subject TEXT,
                last_activity_at INTEGER,
                authors TEXT,
                assignees TEXT,
                shared_labels TEXT,
                web_url TEXT,
                messages_count INTEGER,
                created_at INTEGER,
                synced_at INTEGER,
                status TEXT DEFAULT 'open'
            );

            CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                conversation_id TEXT,
                subject TEXT,
                preview TEXT,
                body TEXT,
                from_name TEXT,
                from_address TEXT,
                to_fields TEXT,
                delivered_at INTEGER,
                synced_at INTEGER,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id)
            );

            CREATE TABLE IF NOT EXISTS classifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id TEXT,
                priority TEXT,
                category TEXT,
                reasoning TEXT,
                suggested_action TEXT,
                classified_at INTEGER,
                model TEXT,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id)
            );

            CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id);
            CREATE INDEX IF NOT EXISTS idx_classifications_conversation ON classifications(conversation_id);
            CREATE INDEX IF NOT EXISTS idx_conversations_last_activity ON conversations(last_activity_at);
            CREATE INDEX IF NOT EXISTS idx_conversations_status ON conversations(status);
        " );
    }

    /**
     * Run schema migrations for existing databases
     */
    private function migrateSchema(): void {
        // Check if conversations table exists first
        $tables = $this->db->query( "SELECT name FROM sqlite_master WHERE type='table' AND name='conversations'" )->fetchAll();
        if ( empty( $tables ) ) {
            return; // New database, no migration needed
        }

        $columns = $this->db->query( "PRAGMA table_info(conversations)" )->fetchAll( \PDO::FETCH_ASSOC );
        $column_names = array_column( $columns, 'name' );

        if ( ! in_array( 'status', $column_names ) ) {
            $this->db->exec( "ALTER TABLE conversations ADD COLUMN status TEXT DEFAULT 'open'" );
            $this->db->exec( "UPDATE conversations SET status = 'open' WHERE status IS NULL" );
        }
    }

    /**
     * Upsert a conversation
     */
    public function upsertConversation( array $conv, string $status = 'open' ): void {
        $stmt = $this->db->prepare( "
            INSERT OR REPLACE INTO conversations
            (id, subject, last_activity_at, authors, assignees, shared_labels, web_url, messages_count, created_at, synced_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        " );

        $stmt->execute( [
            $conv['id'],
            $conv['subject'] ?? $conv['latest_message_subject'] ?? null,
            self::parseTimestamp( $conv['last_activity_at'] ?? null ),
            json_encode( $conv['authors'] ?? [] ),
            json_encode( $conv['assignees'] ?? [] ),
            json_encode( $conv['shared_labels'] ?? [] ),
            $conv['web_url'] ?? null,
            $conv['messages_count'] ?? 0,
            self::parseTimestamp( $conv['created_at'] ?? null ),
            time(),
            $status,
        ] );
    }

    /**
     * Mark conversations as closed if not in the given list of IDs
     */
    public function markClosedExcept( array $open_ids ): int {
        if ( empty( $open_ids ) ) {
            $stmt = $this->db->prepare( "UPDATE conversations SET status = 'closed' WHERE status = 'open'" );
            $stmt->execute();
            return $stmt->rowCount();
        }

        // Use a temp table approach to avoid SQLite placeholder limits
        $this->db->exec( "CREATE TEMP TABLE IF NOT EXISTS temp_open_ids (id TEXT PRIMARY KEY)" );
        $this->db->exec( "DELETE FROM temp_open_ids" );

        $insert = $this->db->prepare( "INSERT OR IGNORE INTO temp_open_ids (id) VALUES (?)" );
        foreach ( $open_ids as $id ) {
            $insert->execute( [ $id ] );
        }

        $stmt = $this->db->prepare( "UPDATE conversations SET status = 'closed' WHERE status = 'open' AND id NOT IN (SELECT id FROM temp_open_ids)" );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Mark a single conversation as closed
     */
    public function closeConversation( string $id ): bool {
        $stmt = $this->db->prepare( "UPDATE conversations SET status = 'closed' WHERE id = ?" );
        return $stmt->execute( [ $id ] ) && $stmt->rowCount() > 0;
    }

    /**
     * Get open conversations with messages for export
     */
    public function getOpenConversations( ?int $since = null ): array {
        $sql = "SELECT c.* FROM conversations c WHERE c.status = 'open'";
        $params = [];

        if ( $since !== null ) {
            $sql .= " AND c.last_activity_at >= ?";
            $params[] = $since;
        }

        $sql .= " ORDER BY c.last_activity_at DESC";

        $stmt = $this->db->prepare( $sql );
        $stmt->execute( $params );
        $conversations = $stmt->fetchAll( \PDO::FETCH_ASSOC );

        foreach ( $conversations as &$conv ) {
            $stmt = $this->db->prepare( "SELECT * FROM messages WHERE conversation_id = ? ORDER BY delivered_at ASC" );
            $stmt->execute( [ $conv['id'] ] );
            $conv['messages'] = $stmt->fetchAll( \PDO::FETCH_ASSOC );
        }

        return $conversations;
    }

    /**
     * Check if conversation needs message sync (new or updated)
     */
    public function needsMessageSync( string $id, int $last_activity_at ): bool {
        $stmt = $this->db->prepare( "SELECT last_activity_at FROM conversations WHERE id = ?" );
        $stmt->execute( [ $id ] );
        $row = $stmt->fetch( \PDO::FETCH_ASSOC );

        if ( ! $row ) {
            return true; // New conversation
        }

        return $row['last_activity_at'] < $last_activity_at;
    }

    /**
     * Upsert a message
     */
    public function upsertMessage( array $msg, string $conversation_id ): void {
        $stmt = $this->db->prepare( "
            INSERT OR REPLACE INTO messages
            (id, conversation_id, subject, preview, body, from_name, from_address, to_fields, delivered_at, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        " );

        $from = $msg['from_field'] ?? [];

        $stmt->execute( [
            $msg['id'],
            $conversation_id,
            $msg['subject'] ?? null,
            $msg['preview'] ?? null,
            $msg['body'] ?? null,
            $from['name'] ?? null,
            $from['address'] ?? null,
            json_encode( $msg['to_fields'] ?? [] ),
            self::parseTimestamp( $msg['delivered_at'] ?? null ),
            time(),
        ] );
    }

    /**
     * Get all conversations with optional filters
     */
    public function getConversations( array $filters = [] ): array {
        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM classifications cl WHERE cl.conversation_id = c.id) as has_classification,
                (SELECT m.subject FROM messages m WHERE m.conversation_id = c.id ORDER BY m.delivered_at ASC LIMIT 1) as message_subject,
                (SELECT m.preview FROM messages m WHERE m.conversation_id = c.id ORDER BY m.delivered_at DESC LIMIT 1) as latest_preview
                FROM conversations c";
        $where = [];
        $params = [];

        if ( isset( $filters['unclassified'] ) && $filters['unclassified'] ) {
            $where[] = "c.id NOT IN (SELECT conversation_id FROM classifications)";
        }

        if ( isset( $filters['priority'] ) ) {
            $where[] = "c.id IN (SELECT conversation_id FROM classifications WHERE priority = ?)";
            $params[] = $filters['priority'];
        }

        if ( isset( $filters['since'] ) ) {
            $where[] = "c.last_activity_at >= ?";
            $params[] = $filters['since'];
        }

        if ( isset( $filters['subject'] ) ) {
            $where[] = "c.subject LIKE ?";
            $params[] = '%' . $filters['subject'] . '%';
        }

        if ( isset( $filters['status'] ) ) {
            $where[] = "c.status = ?";
            $params[] = $filters['status'];
        }

        if ( ! empty( $where ) ) {
            $sql .= " WHERE " . implode( " AND ", $where );
        }

        $sql .= " ORDER BY c.last_activity_at DESC";

        if ( isset( $filters['limit'] ) ) {
            $sql .= " LIMIT " . (int) $filters['limit'];
        }

        $stmt = $this->db->prepare( $sql );
        $stmt->execute( $params );
        return $stmt->fetchAll( \PDO::FETCH_ASSOC );
    }

    /**
     * Get a single conversation with its messages
     */
    public function getConversation( string $id ): ?array {
        $stmt = $this->db->prepare( "SELECT * FROM conversations WHERE id = ?" );
        $stmt->execute( [ $id ] );
        $conv = $stmt->fetch( \PDO::FETCH_ASSOC );

        if ( ! $conv ) {
            return null;
        }

        $stmt = $this->db->prepare( "SELECT * FROM messages WHERE conversation_id = ? ORDER BY delivered_at ASC" );
        $stmt->execute( [ $id ] );
        $conv['messages'] = $stmt->fetchAll( \PDO::FETCH_ASSOC );

        $stmt = $this->db->prepare( "SELECT * FROM classifications WHERE conversation_id = ? ORDER BY classified_at DESC LIMIT 1" );
        $stmt->execute( [ $id ] );
        $conv['classification'] = $stmt->fetch( \PDO::FETCH_ASSOC ) ?: null;

        return $conv;
    }

    /**
     * Get unclassified conversations for AI processing
     */
    public function getUnclassifiedConversations(): array {
        $sql = "SELECT c.*,
                (SELECT GROUP_CONCAT(m.id) FROM messages m WHERE m.conversation_id = c.id) as message_ids
                FROM conversations c
                WHERE c.id NOT IN (SELECT conversation_id FROM classifications)
                ORDER BY c.last_activity_at DESC";

        $stmt = $this->db->query( $sql );
        $conversations = $stmt->fetchAll( \PDO::FETCH_ASSOC );

        // Fetch messages for each conversation
        foreach ( $conversations as &$conv ) {
            $stmt = $this->db->prepare( "SELECT * FROM messages WHERE conversation_id = ? ORDER BY delivered_at ASC" );
            $stmt->execute( [ $conv['id'] ] );
            $conv['messages'] = $stmt->fetchAll( \PDO::FETCH_ASSOC );
        }

        return $conversations;
    }

    /**
     * Save a classification
     */
    public function saveClassification( string $conversation_id, array $classification ): void {
        $stmt = $this->db->prepare( "
            INSERT INTO classifications
            (conversation_id, priority, category, reasoning, suggested_action, classified_at, model)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        " );

        $stmt->execute( [
            $conversation_id,
            $classification['priority'],
            $classification['category'],
            $classification['reasoning'] ?? null,
            $classification['suggested_action'] ?? null,
            time(),
            $classification['model'] ?? 'manual',
        ] );
    }

    /**
     * Get database stats
     */
    public function getStats(): array {
        return [
            'conversations'      => $this->db->query( "SELECT COUNT(*) FROM conversations" )->fetchColumn(),
            'open_conversations' => $this->db->query( "SELECT COUNT(*) FROM conversations WHERE status = 'open'" )->fetchColumn(),
            'messages'           => $this->db->query( "SELECT COUNT(*) FROM messages" )->fetchColumn(),
            'classified'         => $this->db->query( "SELECT COUNT(DISTINCT conversation_id) FROM classifications" )->fetchColumn(),
            'oldest_message'     => $this->db->query( "SELECT MIN(delivered_at) FROM messages WHERE delivered_at > 0" )->fetchColumn() ?: null,
            'newest_message'     => $this->db->query( "SELECT MAX(delivered_at) FROM messages WHERE delivered_at > 0" )->fetchColumn() ?: null,
        ];
    }

    /**
     * Find conversation by partial ID
     */
    public function findByPartialId( string $partial_id ): ?string {
        $stmt = $this->db->prepare( "SELECT id FROM conversations WHERE id LIKE ? LIMIT 1" );
        $stmt->execute( [ $partial_id . '%' ] );
        $row = $stmt->fetch( \PDO::FETCH_ASSOC );
        return $row ? $row['id'] : null;
    }

    /**
     * Search conversations by keyword in subject, body, or author
     */
    public function searchConversations( string $term, array $filters = [] ): array {
        $field = $filters['field'] ?? 'subject';
        $status = $filters['status'] ?? null;
        $limit = (int) ( $filters['limit'] ?? 50 );

        $params = [];

        if ( $field === 'body' ) {
            $sql = "SELECT DISTINCT c.*,
                    (SELECT COUNT(*) FROM classifications cl WHERE cl.conversation_id = c.id) as has_classification,
                    (SELECT m2.subject FROM messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.delivered_at ASC LIMIT 1) as message_subject
                    FROM conversations c
                    JOIN messages m ON m.conversation_id = c.id
                    WHERE m.body LIKE ?";
            $params[] = '%' . $term . '%';
        } elseif ( $field === 'from' ) {
            $sql = "SELECT DISTINCT c.*,
                    (SELECT COUNT(*) FROM classifications cl WHERE cl.conversation_id = c.id) as has_classification,
                    (SELECT m2.subject FROM messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.delivered_at ASC LIMIT 1) as message_subject
                    FROM conversations c
                    JOIN messages m ON m.conversation_id = c.id
                    WHERE (m.from_name LIKE ? OR m.from_address LIKE ?)";
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
        } else {
            $sql = "SELECT c.*,
                    (SELECT COUNT(*) FROM classifications cl WHERE cl.conversation_id = c.id) as has_classification,
                    (SELECT m.subject FROM messages m WHERE m.conversation_id = c.id ORDER BY m.delivered_at ASC LIMIT 1) as message_subject
                    FROM conversations c
                    WHERE c.subject LIKE ?";
            $params[] = '%' . $term . '%';
        }

        if ( $status ) {
            $sql .= " AND c.status = ?";
            $params[] = $status;
        }

        $before = $filters['before'] ?? null;
        $after  = $filters['after'] ?? null;

        if ( $before ) {
            $sql .= " AND c.last_activity_at <= ?";
            $params[] = $before;
        }

        if ( $after ) {
            $sql .= " AND c.last_activity_at >= ?";
            $params[] = $after;
        }

        $sql .= " ORDER BY c.last_activity_at DESC LIMIT " . $limit;

        $stmt = $this->db->prepare( $sql );
        $stmt->execute( $params );
        return $stmt->fetchAll( \PDO::FETCH_ASSOC );
    }
}
