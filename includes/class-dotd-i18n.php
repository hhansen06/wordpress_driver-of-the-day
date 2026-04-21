<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runtime i18n fallback for driver-of-the-day.
 *
 * Why this exists:
 * - The plugin currently has no .mo files.
 * - We still want language switching to follow the active Multilang language.
 *
 * Strategy:
 * - Intercept gettext calls for this plugin's text domain.
 * - If active language is English, map German source strings to English.
 */
class DOTD_I18N {

	/** @var array<string,string> */
	private static $de_to_en = [
		'Ungültige Anfrage. Bitte Seite neu laden.' => 'Invalid request. Please reload the page.',
		'Ungültige Eingabe.' => 'Invalid input.',
		'Event-Daten nicht verfügbar.' => 'Event data is not available.',
		'Ungültiger Teilnehmer.' => 'Invalid participant.',
		'Die Abstimmung ist derzeit nicht aktiv.' => 'Voting is currently not active.',
		'Du hast bereits abgestimmt.' => 'You have already voted.',
		'Event-Daten konnten nicht geladen werden. Bitte später erneut versuchen.' => 'Event data could not be loaded. Please try again later.',
		'Wähle deinen Fahrer des Tages' => 'Choose your driver of the day',
		'Jetzt abstimmen' => 'Vote now',
		'Bestätigen' => 'Confirm',
		'Abbrechen' => 'Cancel',
		'Aktuelle Ergebnisse' => 'Current results',
		'Stimmen gesamt' => 'Total votes',
		'Die Abstimmung ist beendet.' => 'Voting is closed.',
		'Die Abstimmung beginnt am' => 'Voting starts on',
		'Fehler beim Abstimmen. Bitte erneut versuchen.' => 'Voting failed. Please try again.',
		'Beifahrer' => 'Co-driver',
		'Start-Nr.' => 'Start no.',
		'Karte auswählen, dann bestätigen' => 'Select a card, then confirm',
		'JavaScript muss aktiviert sein, um an der Abstimmung teilzunehmen.' => 'JavaScript must be enabled to participate in voting.',
		'API-Konfiguration' => 'API configuration',
		'Verbindungsdaten zur rallyestage.de API.' => 'Connection settings for the rallyestage.de API.',
		'Event-ID' => 'Event ID',
		'Bearer Token' => 'Bearer token',
		'Numerische ID des Events in der rallyestage.de-API.' => 'Numeric event ID in the rallyestage.de API.',
		'Bearer-Token für den API-Zugriff. Wird verschlüsselt gespeichert.' => 'Bearer token for API access. Stored encrypted.',
		'Einstellungen speichern' => 'Save settings',
		'Aktionen' => 'Actions',
		'API-Cache leeren' => 'Clear API cache',
		'Wirklich alle Stimmen für dieses Event löschen?' => 'Really delete all votes for this event?',
		'Stimmen zurücksetzen' => 'Reset votes',
		'Aktuelle Abstimmungsergebnisse' => 'Current voting results',
		'Teilnehmerliste konnte nicht geladen werden. Bitte Event-ID und Bearer-Token prüfen.' => 'Participant list could not be loaded. Please verify event ID and bearer token.',
		'Fahrer / Beifahrer' => 'Driver / Co-driver',
		'Fahrzeug' => 'Vehicle',
		'Klasse' => 'Class',
		'Stimmen' => 'Votes',
		'Keine Berechtigung.' => 'Insufficient permissions.',
	];

	public static function init(): void {
		add_filter( 'gettext', [ __CLASS__, 'translate' ], 20, 3 );
		add_filter( 'gettext_with_context', [ __CLASS__, 'translate_with_context' ], 20, 4 );
	}

	public static function translate( string $translated, string $text, string $domain ): string {
		if ( 'driver-of-the-day' !== $domain ) {
			return $translated;
		}

		if ( self::active_language() !== 'en' ) {
			return $translated;
		}

		return self::$de_to_en[ $text ] ?? $translated;
	}

	public static function translate_with_context( string $translated, string $text, string $context, string $domain ): string {
		return self::translate( $translated, $text, $domain );
	}

	private static function active_language(): string {
		if ( function_exists( 'multilang_current' ) ) {
			$lang = sanitize_key( (string) multilang_current() );
			if ( $lang ) {
				return $lang;
			}
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return sanitize_key( strtolower( substr( (string) $locale, 0, 2 ) ) );
	}
}
