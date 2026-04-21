<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all database interactions for the Driver of the Day plugin.
 */
class DOTD_DB {

	const TABLE = 'dotd_votes';

	/** Returns the full (prefixed) table name. */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Creates the votes table on plugin activation. */
	public static function create_table(): void {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			participant_id BIGINT UNSIGNED NOT NULL,
			ip_hash CHAR(64) NOT NULL,
			cookie_token CHAR(36) NOT NULL,
			voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_ip_event     (event_id, ip_hash),
			UNIQUE KEY uniq_cookie_event (event_id, cookie_token),
			KEY idx_participant          (event_id, participant_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Checks whether an IP hash has already voted for this event. */
	public static function has_voted_by_ip( int $event_id, string $ip_hash ): bool {
		global $wpdb;
		$table = self::table_name();
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE event_id = %d AND ip_hash = %s", $event_id, $ip_hash )
		);
		return (int) $count > 0;
	}

	/** Checks whether a cookie token has already voted for this event. */
	public static function has_voted_by_cookie( int $event_id, string $cookie_token ): bool {
		global $wpdb;
		$table = self::table_name();
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE event_id = %d AND cookie_token = %s", $event_id, $cookie_token )
		);
		return (int) $count > 0;
	}

	/**
	 * Insert a new vote row.
	 * Returns true on success, false if a duplicate key was hit (already voted).
	 */
	public static function insert_vote( int $event_id, int $participant_id, string $ip_hash, string $cookie_token ): bool {
		global $wpdb;
		$result = $wpdb->insert(
			self::table_name(),
			[
				'event_id'       => $event_id,
				'participant_id' => $participant_id,
				'ip_hash'        => $ip_hash,
				'cookie_token'   => $cookie_token,
				'voted_at'       => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		return $result !== false;
	}

	/**
	 * Returns vote counts grouped by participant_id for the given event.
	 *
	 * @return array<int, int>  [ participant_id => vote_count ]
	 */
	public static function get_results( int $event_id ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT participant_id, COUNT(*) AS cnt FROM `{$table}` WHERE event_id = %d GROUP BY participant_id", $event_id ),
			ARRAY_A
		);
		$results = [];
		foreach ( $rows as $row ) {
			$results[ (int) $row['participant_id'] ] = (int) $row['cnt'];
		}
		return $results;
	}

	/** Returns the total number of votes for the given event. */
	public static function get_total_votes( int $event_id ): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE event_id = %d", $event_id )
		);
	}

	/** Deletes all votes for the given event. */
	public static function reset_votes( int $event_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), [ 'event_id' => $event_id ], [ '%d' ] );
	}
}
