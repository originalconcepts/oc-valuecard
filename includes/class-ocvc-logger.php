<?php
/**
 * Safe, opt-in logger.
 *
 * The legacy plugin wrote every raw SOAP request — including VCToken, POSId and
 * both passwords — into inc/requests.log inside the plugin folder, where it was
 * potentially downloadable over HTTP. This logger:
 *   - is disabled unless "debug logging" is turned on in settings,
 *   - writes to a protected directory under wp-content/uploads,
 *   - redacts credentials and OTP codes before anything is written.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Logger {

	/**
	 * Cached absolute path to the log directory.
	 *
	 * @var string|null
	 */
	private static $dir = null;

	/**
	 * Return the protected log directory, creating it if needed.
	 *
	 * @return string
	 */
	public static function dir() {
		if ( null !== self::$dir ) {
			return self::$dir;
		}

		$uploads   = wp_upload_dir();
		self::$dir = trailingslashit( $uploads['basedir'] ) . 'oc-valuecard';

		return self::$dir;
	}

	/**
	 * Create the log directory and lock it down from web access.
	 *
	 * @return void
	 */
	public static function install() {
		$dir = self::dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Deny direct HTTP access (Apache) and hide directory listing.
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
	}

	/**
	 * Whether logging is currently enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return class_exists( 'OCVC_Settings' ) && OCVC_Settings::get_bool( 'debug_logging' );
	}

	/**
	 * Write a log entry (no-op unless logging is enabled).
	 *
	 * @param string $operation Short label for the operation.
	 * @param mixed  $context   Any context (array/object/string). Credentials are redacted.
	 * @return void
	 */
	public static function log( $operation, $context = null ) {
		if ( ! self::enabled() ) {
			return;
		}

		self::install();

		$line  = '[' . gmdate( 'Y-m-d H:i:s' ) . " UTC] {$operation}\n";
		$line .= self::redact( self::stringify( $context ) ) . "\n";
		$line .= "----------------------------------------\n";

		// Monthly rotation keeps files small and easy to purge.
		$file = trailingslashit( self::dir() ) . 'oc-valuecard-' . gmdate( 'Y-m' ) . '.log';
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}

	/**
	 * Convert arbitrary context to a string.
	 *
	 * @param mixed $context Context value.
	 * @return string
	 */
	private static function stringify( $context ) {
		if ( is_string( $context ) ) {
			return $context;
		}
		return wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Redact credentials and OTP codes from a string before writing.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function redact( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return (string) $text;
		}

		// XML tags: <VCToken>..</VCToken>, <POSPassword>..</POSPassword>, etc.
		$xml_tags = array( 'VCToken', 'POSPassword', 'CashiersPassword', 'PIN', 'OtpCode', 'Otp' );
		foreach ( $xml_tags as $tag ) {
			$text = preg_replace( '#(<' . $tag . '>)(.*?)(</' . $tag . '>)#is', '$1***$3', $text );
		}

		// JSON keys: "PosPassword":"...", "VcToken":"...", etc. (case-insensitive).
		$json_keys = array( 'VcToken', 'VCToken', 'PosPassword', 'POSPassword', 'CashiersPassword', 'OtpCode', 'Otp' );
		foreach ( $json_keys as $key ) {
			$text = preg_replace( '#("' . preg_quote( $key, '#' ) . '"\s*:\s*")[^"]*(")#i', '$1***$2', $text );
		}

		return $text;
	}
}
