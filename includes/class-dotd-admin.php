<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page for Driver of the Day.
 *
 * Settings stored:
 *  - dotd_event_id      (int)
 *  - dotd_bearer_token  (string, stored encrypted in DB as WP option)
 *
 * Admin actions:
 *  - Reset votes for the configured event
 *  - Clear API cache
 */
class DOTD_Admin {

	const MENU_SLUG    = 'driver-of-the-day';
	const OPTION_GROUP = 'dotd_settings_group';

	public static function init(): void {
		add_action( 'admin_menu',    [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_dotd_reset_votes',  [ __CLASS__, 'handle_reset_votes' ] );
		add_action( 'admin_post_dotd_clear_cache',  [ __CLASS__, 'handle_clear_cache' ] );
		add_action( 'admin_enqueue_scripts',        [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'Driver of the Day', 'driver-of-the-day' ),
			__( 'Driver of the Day', 'driver-of-the-day' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting( self::OPTION_GROUP, 'dotd_event_id', [
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'absint',
		] );
		register_setting( self::OPTION_GROUP, 'dotd_bearer_token', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		// Section: API
		add_settings_section(
			'dotd_api_section',
			__( 'API-Konfiguration', 'driver-of-the-day' ),
			static function () {
				echo '<p>' . esc_html__( 'Verbindungsdaten zur rallyestage.de API.', 'driver-of-the-day' ) . '</p>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'dotd_event_id',
			__( 'Event-ID', 'driver-of-the-day' ),
			[ __CLASS__, 'field_event_id' ],
			self::MENU_SLUG,
			'dotd_api_section'
		);

		add_settings_field(
			'dotd_bearer_token',
			__( 'Bearer Token', 'driver-of-the-day' ),
			[ __CLASS__, 'field_bearer_token' ],
			self::MENU_SLUG,
			'dotd_api_section'
		);
	}

	public static function field_event_id(): void {
		$value = absint( get_option( 'dotd_event_id', 1 ) );
		printf(
			'<input type="number" id="dotd_event_id" name="dotd_event_id" value="%d" min="1" class="small-text" required>',
			$value
		);
		echo '<p class="description">' . esc_html__( 'Numerische ID des Events in der rallyestage.de-API.', 'driver-of-the-day' ) . '</p>';
	}

	public static function field_bearer_token(): void {
		$value = get_option( 'dotd_bearer_token', '' );
		printf(
			'<input type="password" id="dotd_bearer_token" name="dotd_bearer_token" value="%s" class="regular-text" autocomplete="off">',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Bearer-Token für den API-Zugriff. Wird verschlüsselt gespeichert.', 'driver-of-the-day' ) . '</p>';
	}

	/** Renders the full admin page. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$event_id = absint( get_option( 'dotd_event_id', 1 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Driver of the Day', 'driver-of-the-day' ); ?></h1>

			<?php settings_errors( self::OPTION_GROUP ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Einstellungen speichern', 'driver-of-the-day' ) );
				?>
			</form>

			<hr>

			<!-- Cache & Reset actions -->
			<h2><?php esc_html_e( 'Aktionen', 'driver-of-the-day' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:1rem;">
				<input type="hidden" name="action"   value="dotd_clear_cache">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
				<?php wp_nonce_field( 'dotd_clear_cache_' . $event_id, 'dotd_nonce' ); ?>
				<?php submit_button( __( 'API-Cache leeren', 'driver-of-the-day' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"
				  onsubmit="return confirm('<?php esc_attr_e( 'Wirklich alle Stimmen für dieses Event löschen?', 'driver-of-the-day' ); ?>')">
				<input type="hidden" name="action"   value="dotd_reset_votes">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
				<?php wp_nonce_field( 'dotd_reset_votes_' . $event_id, 'dotd_nonce' ); ?>
				<?php submit_button( __( 'Stimmen zurücksetzen', 'driver-of-the-day' ), 'delete', 'submit', false ); ?>
			</form>

			<hr>

			<!-- Current results -->
			<h2><?php esc_html_e( 'Aktuelle Abstimmungsergebnisse', 'driver-of-the-day' ); ?></h2>
			<?php self::render_results_table( $event_id ); ?>
		</div>
		<?php
	}

	/** Renders a sortable table with current vote counts. */
	private static function render_results_table( int $event_id ): void {
		$participants = DOTD_API::get_participants( $event_id );
		$results      = DOTD_DB::get_results( $event_id );
		$total        = DOTD_DB::get_total_votes( $event_id );

		if ( ! $participants ) {
			echo '<p>' . esc_html__( 'Teilnehmerliste konnte nicht geladen werden. Bitte Event-ID und Bearer-Token prüfen.', 'driver-of-the-day' ) . '</p>';
			return;
		}

		$participants = DOTD_API::sort_participants( $participants, $event_id );

		printf( '<p><strong>%s:</strong> %d</p>', esc_html__( 'Stimmen gesamt', 'driver-of-the-day' ), $total );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>'
			. '<th>#</th>'
			. '<th>' . esc_html__( 'Start-Nr.', 'driver-of-the-day' ) . '</th>'
			. '<th>' . esc_html__( 'Fahrer / Beifahrer', 'driver-of-the-day' ) . '</th>'
			. '<th>' . esc_html__( 'Fahrzeug', 'driver-of-the-day' ) . '</th>'
			. '<th>' . esc_html__( 'Klasse', 'driver-of-the-day' ) . '</th>'
			. '<th>' . esc_html__( 'Stimmen', 'driver-of-the-day' ) . '</th>'
			. '<th>%</th>'
			. '</tr></thead><tbody>';

		$rank = 1;
		foreach ( $participants as $p ) {
			$pid   = (int) $p['id'];
			$votes = $results[ $pid ] ?? 0;
			$pct   = $total > 0 ? round( $votes / $total * 100, 1 ) : 0.0;

			printf(
				'<tr>'
				. '<td>%d</td>'
				. '<td>%s</td>'
				. '<td><strong>%s</strong><br><small>%s</small></td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%d</td>'
				. '<td>%s %%</td>'
				. '</tr>',
				$rank++,
				esc_html( $p['start_nr'] ?? '' ),
				esc_html( $p['driver_name'] ?? '' ),
				esc_html( $p['codriver_name'] ?? '' ),
				esc_html( $p['vehicle'] ?? '' ),
				esc_html( $p['klasse'] ?? '' ),
				$votes,
				esc_html( (string) $pct )
			);
		}

		echo '</tbody></table>';
	}

	// -------------------------------------------------------------------------
	// Admin-post handlers
	// -------------------------------------------------------------------------

	public static function handle_reset_votes(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'driver-of-the-day' ) );
		}
		$event_id = absint( $_POST['event_id'] ?? 0 );
		check_admin_referer( 'dotd_reset_votes_' . $event_id, 'dotd_nonce' );
		DOTD_DB::reset_votes( $event_id );
		wp_safe_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'dotd_msg' => 'votes_reset' ],
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	public static function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'driver-of-the-day' ) );
		}
		$event_id = absint( $_POST['event_id'] ?? 0 );
		check_admin_referer( 'dotd_clear_cache_' . $event_id, 'dotd_nonce' );
		DOTD_API::clear_cache( $event_id );
		wp_safe_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'dotd_msg' => 'cache_cleared' ],
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	public static function enqueue_admin_assets( string $hook ): void {
		if ( $hook !== 'settings_page_' . self::MENU_SLUG ) {
			return;
		}
		wp_enqueue_style(
			'dotd-admin',
			DOTD_PLUGIN_URL . 'assets/css/dotd-admin.css',
			[],
			DOTD_VERSION
		);
	}
}
