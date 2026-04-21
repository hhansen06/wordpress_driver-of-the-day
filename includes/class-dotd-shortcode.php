<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the [driver_of_the_day] shortcode.
 *
 * Usage:  [driver_of_the_day]
 *         [driver_of_the_day event_id="2"]   ← overrides the admin setting
 *
 * All dynamic data (participants, voting state, results) is embedded inline
 * so the page renders immediately without an extra AJAX round-trip on load.
 * Only vote submission uses AJAX.
 */
class DOTD_Shortcode {

	public static function init(): void {
		add_shortcode( 'driver_of_the_day', [ __CLASS__, 'render' ] );
	}

	public static function render( array $atts ): string {
		$atts = shortcode_atts(
			[ 'event_id' => (int) get_option( 'dotd_event_id', 1 ) ],
			$atts,
			'driver_of_the_day'
		);
		$event_id = absint( $atts['event_id'] );

		// Enqueue frontend assets
		self::enqueue_assets();

		// Fetch event data
		$event = DOTD_API::get_event( $event_id );

		if ( ! $event ) {
			return '<p class="dotd-error">'
				. esc_html__( 'Event-Daten konnten nicht geladen werden. Bitte später erneut versuchen.', 'driver-of-the-day' )
				. '</p>';
		}

		$today     = current_time( 'Y-m-d' );
		$date_from = $event['date_from'] ?? '';
		$date_to   = $event['date_to']   ?? '';

		// Determine phase: 'before' | 'open' | 'closed'
		if ( $today < $date_from ) {
			$phase = 'before';
		} elseif ( $today <= $date_to ) {
			$phase = 'open';
		} else {
			$phase = 'closed';
		}

		$already_voted = ( $phase === 'open' ) && DOTD_Vote::visitor_has_voted( $event_id );
		$participants  = $event['participants'] ?? [];

		// Fetch results only when needed (already voted, or voting closed)
		$results = [];
		$total   = 0;
		if ( $already_voted || $phase === 'closed' ) {
			$results = DOTD_DB::get_results( $event_id );
			$total   = DOTD_DB::get_total_votes( $event_id );
		}

		// Build the inline data payload for JS
		$js_data = [
			'eventId'      => $event_id,
			'phase'        => $phase,
			'dateFrom'     => $date_from,
			'dateTo'       => $date_to,
			'alreadyVoted' => $already_voted,
			'participants' => $participants,
			'results'      => $results,
			'total'        => $total,
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'dotd_vote_nonce' ),
			'i18n'         => [
				'btnVote'         => __( 'Jetzt abstimmen', 'driver-of-the-day' ),
				'btnConfirm'      => __( 'Bestätigen', 'driver-of-the-day' ),
				'btnCancel'       => __( 'Abbrechen', 'driver-of-the-day' ),
				'alreadyVoted'    => __( 'Du hast bereits abgestimmt.', 'driver-of-the-day' ),
				'resultsHeadline' => __( 'Aktuelle Ergebnisse', 'driver-of-the-day' ),
				'totalVotes'      => __( 'Stimmen gesamt', 'driver-of-the-day' ),
				'votingClosed'    => __( 'Die Abstimmung ist beendet.', 'driver-of-the-day' ),
				'votingNotOpen'   => __( 'Die Abstimmung beginnt am', 'driver-of-the-day' ),
				'errorGeneric'    => __( 'Fehler beim Abstimmen. Bitte erneut versuchen.', 'driver-of-the-day' ),
				'coDriver'        => __( 'Beifahrer', 'driver-of-the-day' ),
				'startNr'         => __( 'Start-Nr.', 'driver-of-the-day' ),
				'selectPrompt'    => __( 'Karte auswählen, dann bestätigen', 'driver-of-the-day' ),
				'voteUnitSingular'=> __( 'Stimme', 'driver-of-the-day' ),
				'voteUnitPlural'  => __( 'Stimmen', 'driver-of-the-day' ),
			],
		];

		// Each shortcode instance gets a unique DOM id
		static $instance = 0;
		$instance++;
		$widget_id = 'dotd-widget-' . $instance;
		$widget_style = '';

		// Bam theme exposes primary color via theme mod (not CSS variable).
		if ( get_stylesheet() === 'bam' || get_template() === 'bam' ) {
			$bam_primary = sanitize_hex_color( (string) get_theme_mod( 'bam_primary_color', '#ff4f4f' ) );
			if ( ! empty( $bam_primary ) ) {
				$widget_style = '--dotd-primary:' . $bam_primary . ';';
			}
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $widget_id ); ?>" class="dotd-widget" style="<?php echo esc_attr( $widget_style ); ?>" aria-live="polite">
			<noscript><?php esc_html_e( 'JavaScript muss aktiviert sein, um an der Abstimmung teilzunehmen.', 'driver-of-the-day' ); ?></noscript>
		</div>
		<script>
		(function () {
			var dotdInstances = window.dotdInstances || [];
			dotdInstances.push({
				widgetId: <?php echo wp_json_encode( $widget_id ); ?>,
				data: <?php echo wp_json_encode( $js_data ); ?>
			});
			window.dotdInstances = dotdInstances;
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	private static function enqueue_assets(): void {
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$enqueued = true;

		wp_enqueue_style(
			'dotd-frontend',
			DOTD_PLUGIN_URL . 'assets/css/dotd-frontend.css',
			[],
			DOTD_VERSION
		);
		wp_enqueue_script(
			'dotd-frontend',
			DOTD_PLUGIN_URL . 'assets/js/dotd-frontend.js',
			[],
			DOTD_VERSION,
			true  // load in footer
		);
	}
}
