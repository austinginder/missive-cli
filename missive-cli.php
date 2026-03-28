<?php
/**
 * Plugin Name: Missive CLI
 * Description: WP-CLI commands for syncing and managing Missive inbox conversations
 * Version: 1.0.0
 * Author: Austin Ginder
 * Author URI: https://github.com/austinginder/missive-cli/
 * Requires PHP: 8.0
 *
 * Configuration:
 *   define('MISSIVE_API_KEY', 'your-api-key');
 *   define('MISSIVE_TEAM_ID', 'optional-team-id');
 *   define('MISSIVE_API_NAME', 'Your Name');
 */

namespace MissiveCLI;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// PSR-4 style autoloader
spl_autoload_register( function ( $class ) {
    $prefix = 'MissiveCLI\\';
    $base_dir = __DIR__ . '/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );

    // Convert namespace to path: MissiveCLI\Remote\Missive -> app/Remote/Missive.php
    // MissiveCLI\CLI\Commands -> cli/Commands.php
    // MissiveCLI\Database -> app/Database.php
    if ( strpos( $relative_class, 'CLI\\' ) === 0 ) {
        $file = $base_dir . 'cli/' . str_replace( '\\', '/', substr( $relative_class, 4 ) ) . '.php';
    } elseif ( strpos( $relative_class, 'Remote\\' ) === 0 ) {
        $file = $base_dir . 'app/Remote/' . str_replace( '\\', '/', substr( $relative_class, 7 ) ) . '.php';
    } else {
        $file = $base_dir . 'app/' . str_replace( '\\', '/', $relative_class ) . '.php';
    }

    if ( file_exists( $file ) ) {
        require $file;
    }
});

// Self-contained updater (checks GitHub for new releases)
new Updater();

// Register WP-CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'missive', CLI\Commands::class );
}
