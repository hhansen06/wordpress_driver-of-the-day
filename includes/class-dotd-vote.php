<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles vote submission via WordPress AJAX.
 *
 * Anti-manipulation:
 *  - IP hash  (SHA-256 of REMOTE_ADDR + WP auth salt)
 *  - Cookie token (random UUID stored in browser + DB)
 * Both are stored per event. Either match → already voted.
 */
class DOTD_Vote {

	/** Cookie name template (sprintf placeholder for event_id). */
	const COOKIE_PREFIX = 'dotd_voted_';

	public static function init(): void {
		// Available to all visitors (no_priv = not logged in, regular = logged in)
		add_action( 'wp_ajax_dotd_submit_vote',        [ __CLASS__, 'handle_submit' ] );
		add_action( 'wp_ajax_nopriv_dotd_submit_vote', [ __CLASS__, 'handle_submit' ] );
	}

	/** AJAX handler: validate, store vote, return results. */
	public static function handle_submit(): void {
		// 1. Nonce check
		if ( ! check_ajax_referer( 'dotd_vote_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Ungültige Anfrage. Bitte Seite neu laden.', 'driver-of-the-day' ) ], 403 );
		}

		// 2. Input validation
		$event_id       = isset( $_POST['event_id'] )       ? absint( $_POST['event_id'] )       : 0;
		$participant_id = isset( $_POST['participant_id'] ) ? absint( $_POST['participant_id'] ) : 0;

		if ( ! $event_id || ! $participant_id ) {
			wp_send_json_error( [ 'message' => __( 'Ungültige Eingabe.', 'driver-of-the-day' ) ], 400 );
		}

		// 3. Verify participant belongs to the event
		$participants = DOTD_API::get_participants( $event_id );
		if ( ! $participants ) {
			wp_send_json_error( [ 'message' => __( 'Event-Daten nicht verfügbar.', 'driver-of-the-day' ) ], 503 );
		}
		$valid_ids = array_column( $participants, 'id' );
		if ( ! in_array( $participant_id, array_map( 'intval', $valid_ids ), true ) ) {
			wp_send_json_error( [ 'message' => __( 'Ungültiger Teilnehmer.', 'driver-of-the-day' ) ], 400 );
		}

		// 4. Voting-period check
		$voting_open = DOTD_API::is_voting_open( $event_id );
		if ( ! $voting_open ) {
			wp_send_json_error( [ 'message' => __( 'Die Abstimmung ist derzeit nicht aktiv.', 'driver-of-the-day' ) ], 403 );
		}

		// 5. Duplicate-vote check (cookie + IP)
		$ip_hash      = self::hash_ip( self::get_client_ip() );
		$cookie_name  = self::COOKIE_PREFIX . $event_id;
		$cookie_token = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

		if ( $cookie_token && DOTD_DB::has_voted_by_cookie( $event_id, $cookie_token ) ) {
			wp_send_json_error( [ 'message' => __( 'Du hast bereits abgestimmt.', 'driver-of-the-day' ), 'already_voted' => true ] );
		}

		if ( DOTD_DB::has_voted_by_ip( $event_id, $ip_hash ) ) {
			wp_send_json_error( [ 'message' => __( 'Du hast bereits abgestimmt.', 'driver-of-the-day' ), 'already_voted' => true ] );
		}

		// 6. Insert vote
		$new_token = wp_generate_uuid4();
		$inserted  = DOTD_DB::insert_vote( $event_id, $participant_id, $ip_hash, $new_token );

		if ( ! $inserted ) {
			// Concurrent duplicate — treat as already voted
			wp_send_json_error( [ 'message' => __( 'Du hast bereits abgestimmt.', 'driver-of-the-day' ), 'already_voted' => true ] );
		}

		// 7. Set cookie (HttpOnly, SameSite=Strict, optional Secure)
		setcookie(
			$cookie_name,
			$new_token,
			[
				'expires'  => time() + 30 * DAY_IN_SECONDS,
				'path'     => '/',
				'domain'   => '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);

		// 8. Return results
		$results = DOTD_DB::get_results( $event_id );
		$total   = DOTD_DB::get_total_votes( $event_id );

		wp_send_json_success( [
			'results'        => $results,
			'total'          => $total,
			'participant_id' => $participant_id,
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Returns the visitor's remote IP address (REMOTE_ADDR only — not spoofable). */
	private static function get_client_ip(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	/** Returns a one-way hash of the IP for privacy-compliant storage. */
	public static function hash_ip( string $ip ): string {
		// wp_hash uses HMAC-MD5 with WP's secret keys — sufficient for this purpose.
		return wp_hash( $ip . 'dotd_ip' );
	}

	/**
	 * Checks (read-only) whether the current visitor has already voted.
	 * Used by the shortcode to embed the state server-side.
	 */
	public static function visitor_has_voted( int $event_id ): bool {
		$ip_hash     = self::hash_ip( self::get_client_ip() );
		$cookie_name = self::COOKIE_PREFIX . $event_id;

		if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
			if ( DOTD_DB::has_voted_by_cookie( $event_id, $token ) ) {
				return true;
			}
		}

		return DOTD_DB::has_voted_by_ip( $event_id, $ip_hash );
	}
}
