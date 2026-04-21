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
	 * Returns saved class order from admin settings.
	 *
	 * @return string[]
	 */
	public static function get_saved_class_order(): array {
		$raw = get_option( 'dotd_class_order', [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$order = [];
		foreach ( $raw as $class_name ) {
			$class_name = trim( sanitize_text_field( (string) $class_name ) );
			if ( $class_name !== '' ) {
				$order[] = $class_name;
			}
		}

		return array_values( array_unique( $order ) );
	}

	/**
	 * Returns class order for a specific event.
	 * Saved order is respected; unknown/new classes are appended.
	 *
	 * @return string[]
	 */
	public static function get_class_order_for_event( int $event_id ): array {
		$saved_order = self::get_saved_class_order();
		$participants = self::get_participants( $event_id );

		if ( ! is_array( $participants ) || empty( $participants ) ) {
			return $saved_order;
		}

		$classes = [];
		foreach ( $participants as $p ) {
			$class_name = trim( sanitize_text_field( (string) ( $p['klasse'] ?? '' ) ) );
			if ( $class_name !== '' ) {
				$classes[] = $class_name;
			}
		}

		$classes = array_values( array_unique( $classes ) );
		natcasesort( $classes );
		$classes = array_values( $classes );

		$ordered = [];
		foreach ( $saved_order as $class_name ) {
			if ( in_array( $class_name, $classes, true ) ) {
				$ordered[] = $class_name;
			}
		}

		foreach ( $classes as $class_name ) {
			if ( ! in_array( $class_name, $ordered, true ) ) {
				$ordered[] = $class_name;
			}
		}

		return $ordered;
	}

	/**
	 * Sort participants by class order first, then numerically by start number.
	 *
	 * @param array[] $participants
	 * @return array[]
	 */
	public static function sort_participants( array $participants, int $event_id ): array {
		$class_order = self::get_class_order_for_event( $event_id );
		$rank_map = [];
		foreach ( $class_order as $index => $class_name ) {
			$rank_map[ $class_name ] = $index;
		}

		usort( $participants, static function ( array $a, array $b ) use ( $rank_map ) {
			$class_a = trim( sanitize_text_field( (string) ( $a['klasse'] ?? '' ) ) );
			$class_b = trim( sanitize_text_field( (string) ( $b['klasse'] ?? '' ) ) );

			$rank_a = $rank_map[ $class_a ] ?? PHP_INT_MAX;
			$rank_b = $rank_map[ $class_b ] ?? PHP_INT_MAX;

			if ( $rank_a !== $rank_b ) {
				return $rank_a <=> $rank_b;
			}

			$start_a = self::start_nr_to_int( (string) ( $a['start_nr'] ?? '' ) );
			$start_b = self::start_nr_to_int( (string) ( $b['start_nr'] ?? '' ) );

			if ( $start_a !== $start_b ) {
				return $start_a <=> $start_b;
			}

			$driver_a = (string) ( $a['driver_name'] ?? '' );
			$driver_b = (string) ( $b['driver_name'] ?? '' );
			return strnatcasecmp( $driver_a, $driver_b );
		} );

		return $participants;
	}

	/** Converts a start number string to an integer for proper numeric sorting. */
	private static function start_nr_to_int( string $start_nr ): int {
		if ( preg_match( '/\d+/', $start_nr, $m ) ) {
			return (int) $m[0];
		}
		return PHP_INT_MAX;
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
