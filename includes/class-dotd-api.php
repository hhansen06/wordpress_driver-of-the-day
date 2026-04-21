<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and caches event data from the rallyestage.de API.
 */
class DOTD_API {

	/** Transient key template (sprintf placeholder for event_id). */
	const CACHE_KEY = 'dotd_event_%d';

	/** Cache duration in seconds (1 hour). */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Returns the full event data array for the given event ID.
	 * Data is cached as a transient. Returns null on failure.
	 *
	 * @return array|null
	 */
	public static function get_event( int $event_id ): ?array {
		$cache_key = sprintf( self::CACHE_KEY, $event_id );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$bearer_token = get_option( 'dotd_bearer_token', '' );
		if ( empty( $bearer_token ) ) {
			return null;
		}

		$url      = DOTD_API_BASE . (int) $event_id;
		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $bearer_token,
				'Accept'        => 'application/json',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		set_transient( $cache_key, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Returns just the participants array for the given event.
	 *
	 * @return array[]|null
	 */
	public static function get_participants( int $event_id ): ?array {
		$event = self::get_event( $event_id );
		if ( ! $event || empty( $event['participants'] ) ) {
			return null;
		}
		return $event['participants'];
	}

	/**
	 * Sort participants by start number using a left-padded sort key.
	 * Examples: 1 -> 001, 9 -> 009, 10 -> 010.
	 *
	 * @param array[] $participants
	 * @return array[]
	 */
	public static function sort_participants( array $participants, int $event_id ): array {
		usort( $participants, static function ( array $a, array $b ) {
			$start_a = self::start_nr_to_sort_key( (string) ( $a['start_nr'] ?? '' ) );
			$start_b = self::start_nr_to_sort_key( (string) ( $b['start_nr'] ?? '' ) );

			if ( $start_a !== $start_b ) {
				return strcmp( $start_a, $start_b );
			}

			$driver_a = (string) ( $a['driver_name'] ?? '' );
			$driver_b = (string) ( $b['driver_name'] ?? '' );
			return strnatcasecmp( $driver_a, $driver_b );
		} );

		return $participants;
	}

	/**
	 * Converts start number to a left-padded sortable key.
	 * Example: 1 -> 001, 9 -> 009, 10 -> 010.
	 */
	private static function start_nr_to_sort_key( string $start_nr ): string {
		if ( preg_match( '/\d+/', $start_nr, $m ) ) {
			return str_pad( (string) (int) $m[0], 3, '0', STR_PAD_LEFT );
		}
		return '999999';
	}

	/** Clears the cached event data for the given event. */
	public static function clear_cache( int $event_id ): void {
		delete_transient( sprintf( self::CACHE_KEY, $event_id ) );
	}

	/**
	 * Determines whether voting is currently open based on the event dates.
	 * Voting is open from date_from (inclusive) through date_to (inclusive).
	 *
	 * @return bool|null  null if event data cannot be fetched
	 */
	public static function is_voting_open( int $event_id ): ?bool {
		$event = self::get_event( $event_id );
		if ( ! $event ) {
			return null;
		}

		$today     = current_time( 'Y-m-d' );
		$date_from = $event['date_from'] ?? '';
		$date_to   = $event['date_to']   ?? '';

		if ( empty( $date_from ) || empty( $date_to ) ) {
			return null;
		}

		return ( $today >= $date_from && $today <= $date_to );
	}
}
