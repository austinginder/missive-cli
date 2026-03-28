<?php
/**
 * Missive API WordPress Wrapper
 *
 * @link https://missiveapp.com/help/api-documentation/rest-endpoints
 */

namespace MissiveCLI\Remote;

class Missive {

    private static $base_url = 'https://public.missiveapp.com/v1';

    /**
     * Get the API key from constant or environment variable
     *
     * @return string
     * @throws \Exception if no API key is configured
     */
    private static function getApiKey() {
        if ( defined( 'MISSIVE_API_KEY' ) && MISSIVE_API_KEY ) {
            return MISSIVE_API_KEY;
        }

        $env_key = getenv( 'MISSIVE_API_KEY' );
        if ( $env_key ) {
            return $env_key;
        }

        throw new \Exception( 'MISSIVE_API_KEY must be defined as a constant or environment variable' );
    }

    /**
     * Generates the Bearer token Authorization header
     *
     * @return string
     */
    private static function getAuthHeader() {
        return 'Bearer ' . self::getApiKey();
    }

    /**
     * Performs a GET request
     *
     * @param string $endpoint The API endpoint (e.g., '/users/me')
     * @param array $parameters Optional query parameters
     * @return mixed Decoded JSON response
     * @throws \Exception on API or network error
     */
    public static function get( $endpoint, $parameters = [] ) {
        $args = [
            'timeout' => 120,
            'connect_timeout' => 30,
            'headers' => [
                'Content-type'  => 'application/json',
                'Authorization' => self::getAuthHeader(),
            ]
        ];

        // Ensure endpoint starts with /
        if ( $endpoint[0] !== '/' ) {
            $endpoint = '/' . $endpoint;
        }

        $url = self::$base_url . $endpoint;
        if ( ! empty( $parameters ) ) {
            $url .= '?' . http_build_query( $parameters );
        }

        return self::request_with_retry( function() use ( $url, $args ) {
            return wp_remote_get( $url, $args );
        } );
    }

    /**
     * Performs a POST request
     *
     * @param string $endpoint The API endpoint
     * @param array $body The request body
     * @return mixed Decoded JSON response
     * @throws \Exception on API or network error
     */
    public static function post( $endpoint, $body = [] ) {
        $args = [
            'timeout' => 120,
            'connect_timeout' => 30,
            'headers' => [
                'Content-type'  => 'application/json',
                'Authorization' => self::getAuthHeader(),
            ],
            'body'   => json_encode( $body ),
            'method' => 'POST',
        ];

        // Ensure endpoint starts with /
        if ( $endpoint[0] !== '/' ) {
            $endpoint = '/' . $endpoint;
        }

        $url = self::$base_url . $endpoint;

        return self::request_with_retry( function() use ( $url, $args ) {
            return wp_remote_post( $url, $args );
        } );
    }

    /**
     * Performs a PATCH request
     *
     * @param string $endpoint The API endpoint
     * @param array $body The request body
     * @return mixed Decoded JSON response
     * @throws \Exception on API or network error
     */
    public static function patch( $endpoint, $body = [] ) {
        $args = [
            'timeout' => 120,
            'connect_timeout' => 30,
            'headers' => [
                'Content-type'  => 'application/json',
                'Authorization' => self::getAuthHeader(),
            ],
            'body'   => json_encode( $body ),
            'method' => 'PATCH',
        ];

        if ( $endpoint[0] !== '/' ) {
            $endpoint = '/' . $endpoint;
        }

        $url = self::$base_url . $endpoint;

        return self::request_with_retry( function() use ( $url, $args ) {
            return wp_remote_request( $url, $args );
        } );
    }

    /**
     * Performs a DELETE request
     *
     * @param string $endpoint The API endpoint
     * @return mixed Decoded JSON response
     * @throws \Exception on API or network error
     */
    public static function delete( $endpoint ) {
        $args = [
            'timeout' => 120,
            'connect_timeout' => 30,
            'headers' => [
                'Content-type'  => 'application/json',
                'Authorization' => self::getAuthHeader(),
            ],
            'method' => 'DELETE',
        ];

        if ( $endpoint[0] !== '/' ) {
            $endpoint = '/' . $endpoint;
        }

        $url = self::$base_url . $endpoint;

        return self::request_with_retry( function() use ( $url, $args ) {
            return wp_remote_request( $url, $args );
        } );
    }

    /**
     * Executes a request callback with retry on 429 rate limits.
     * Uses Retry-After header when available, falls back to exponential backoff.
     * Adds a small delay between all requests to stay under 5 req/sec.
     */
    private static function request_with_retry( callable $request_fn, int $max_retries = 5 ) {
        // Proactive throttle: wait 250ms between requests to stay under 5/sec
        static $last_request_time = 0;
        $now = microtime( true );
        $elapsed = $now - $last_request_time;
        if ( $last_request_time > 0 && $elapsed < 0.25 ) {
            usleep( (int) ( ( 0.25 - $elapsed ) * 1_000_000 ) );
        }
        $last_request_time = microtime( true );

        for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
            $remote = $request_fn();

            if ( is_wp_error( $remote ) ) {
                return self::handle_response( $remote );
            }

            $http_code = wp_remote_retrieve_response_code( $remote );

            if ( $http_code !== 429 ) {
                return self::handle_response( $remote );
            }

            if ( $attempt === $max_retries ) {
                return self::handle_response( $remote );
            }

            // Use Retry-After header if available, otherwise exponential backoff
            $retry_after = wp_remote_retrieve_header( $remote, 'retry-after' );
            $wait = $retry_after ? (int) $retry_after : pow( 2, $attempt + 1 );
            $wait = max( $wait, 1 );

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                $source = $retry_after ? 'Retry-After' : 'backoff';
                \WP_CLI::warning( "Rate limited (429). Waiting {$wait}s ({$source}) before retry " . ( $attempt + 1 ) . "/{$max_retries}..." );
            }
            sleep( $wait );
            $last_request_time = microtime( true );
        }
    }

    /**
     * Handles the response from wp_remote_* calls
     *
     * @param array|\WP_Error $remote The response from WordPress HTTP API
     * @return mixed Decoded JSON on success
     * @throws \Exception on failure
     */
    private static function handle_response( $remote ) {
        if ( is_wp_error( $remote ) ) {
            throw new \Exception( 'HTTP Error: ' . $remote->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $remote );
        $http_code = wp_remote_retrieve_response_code( $remote );

        // Handle empty responses (e.g., DELETE returns no body)
        if ( $http_code >= 400 ) {
            $decoded = json_decode( $body, true );
            $message = $decoded['message'] ?? $decoded['error'] ?? $body ?: "HTTP $http_code";
            if ( is_array( $message ) ) {
                $message = json_encode( $message );
            }
            throw new \Exception( "API error ($http_code): $message" );
        }

        if ( empty( $body ) ) {
            return null;
        }

        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \Exception( 'Invalid JSON response: ' . json_last_error_msg() );
        }

        return $decoded;
    }
}
