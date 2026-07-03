<?php
/**
 * File-upload processing for the File Upload Field block (Pro).
 *
 * Docks onto the free core's field-type seams (cut in core 0.4.0):
 *
 *   - `flinkform_field_blocks`     — registers flinkform/field-file → 'file'.
 *   - `flinkform_field_extras`     — carries allowedTypes + maxSizeMb into
 *                                    the field definition.
 *   - `flinkform_sanitise_field`   — substitutes the PENDING sentinel when a
 *                                    file arrived in $_FILES, so the core's
 *                                    required-check works without knowing
 *                                    anything about uploads.
 *   - `flinkform_validate_field`   — no-op (real validation happens below,
 *                                    where the file bytes are available).
 *   - `flinkform_process_submission` — the actual upload: validate type +
 *                                    size, move via wp_handle_upload() into
 *                                    a protected subdirectory, replace the
 *                                    sentinel with the file URL.
 *
 * SECURITY MODEL
 * --------------
 *   - Extension AND content sniffing via wp_check_filetype_and_ext() against
 *     a fixed extension→mime map intersected with the author's allow-list —
 *     a .php renamed to .pdf fails the content check.
 *   - Stored names are randomised (16 hex chars + sanitised original), so
 *     uploads are not enumerable.
 *   - Files land in uploads/flinkform/<Y>/<m>/ behind an .htaccess that
 *     disables script execution (Apache/LiteSpeed; nginx setups don't execute
 *     PHP from uploads by default) plus an index.html against dir listing.
 *   - Per-field size cap (author setting), always clamped to the server's
 *     wp_max_upload_size().
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Validates + stores visitor file uploads.
 */
final class Uploader {

	/**
	 * Sentinel stored in $clean while the upload is pending processing.
	 * Never persisted: process() replaces it with the file URL or ''.
	 */
	public const PENDING = '__flinkform_file_pending__';

	/**
	 * $_FILES bag the field-file inputs post into.
	 */
	private const FILES_KEY = 'flinkform_files';

	/**
	 * Subdirectory below the uploads basedir.
	 */
	private const SUBDIR = 'flinkform';

	/**
	 * Extension → mime map (wp_check_filetype_and_ext format). The author's
	 * per-field allow-list selects a subset of these; anything outside the
	 * map can never be enabled.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const TYPE_MAP = [
		'pdf'  => [ 'pdf' => 'application/pdf' ],
		'jpg'  => [ 'jpg|jpeg' => 'image/jpeg' ],
		'png'  => [ 'png' => 'image/png' ],
		'webp' => [ 'webp' => 'image/webp' ],
		'gif'  => [ 'gif' => 'image/gif' ],
		'doc'  => [
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		],
		'xls'  => [
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		],
		'txt'  => [ 'txt' => 'text/plain' ],
		'csv'  => [ 'csv' => 'text/csv' ],
		'zip'  => [ 'zip' => 'application/zip' ],
	];

	/**
	 * Whether a usable file arrived for the given field.
	 *
	 * Handles both input shapes: a single-file field posts scalar entries
	 * (`error[field]` is an int), a multi-file field posts arrays
	 * (`error[field]` is a list of ints).
	 *
	 * @param string $field_name
	 * @return bool
	 */
	public static function has_incoming_file( string $field_name ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by the core Handler before any of this runs.
		if ( ! isset( $_FILES[ self::FILES_KEY ]['error'][ $field_name ] ) ) {
			return false;
		}
		$error = $_FILES[ self::FILES_KEY ]['error'][ $field_name ];
		// phpcs:enable

		if ( is_array( $error ) ) {
			foreach ( $error as $code ) {
				if ( UPLOAD_ERR_NO_FILE !== (int) $code ) {
					return true;
				}
			}
			return false;
		}

		return UPLOAD_ERR_NO_FILE !== (int) $error;
	}

	/**
	 * Normalise the $_FILES entries for a field into a list of standard
	 * single-file arrays, regardless of whether the input posted the
	 * scalar (single) or array (multiple) shape. Slots where no file was
	 * selected (UPLOAD_ERR_NO_FILE) are dropped.
	 *
	 * @param string $field_name
	 * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
	 */
	private static function incoming_files( string $field_name ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by the core Handler.
		$bag = isset( $_FILES[ self::FILES_KEY ] ) && is_array( $_FILES[ self::FILES_KEY ] ) ? $_FILES[ self::FILES_KEY ] : [];
		// phpcs:enable

		if ( ! isset( $bag['error'][ $field_name ] ) ) {
			return [];
		}

		$read_slot = static function ( $name, $type, $tmp, $error, $size ): array {
			return [
				'name'     => sanitize_file_name( (string) $name ),
				'type'     => (string) $type,
				'tmp_name' => (string) $tmp,
				'error'    => (int) $error,
				'size'     => (int) $size,
			];
		};

		$files = [];

		if ( is_array( $bag['error'][ $field_name ] ) ) {
			foreach ( array_keys( $bag['error'][ $field_name ] ) as $i ) {
				$slot = $read_slot(
					$bag['name'][ $field_name ][ $i ] ?? '',
					$bag['type'][ $field_name ][ $i ] ?? '',
					$bag['tmp_name'][ $field_name ][ $i ] ?? '',
					$bag['error'][ $field_name ][ $i ] ?? UPLOAD_ERR_NO_FILE,
					$bag['size'][ $field_name ][ $i ] ?? 0
				);
				if ( UPLOAD_ERR_NO_FILE !== $slot['error'] ) {
					$files[] = $slot;
				}
			}
			return $files;
		}

		$slot = $read_slot(
			$bag['name'][ $field_name ] ?? '',
			$bag['type'][ $field_name ] ?? '',
			$bag['tmp_name'][ $field_name ] ?? '',
			$bag['error'][ $field_name ] ?? UPLOAD_ERR_NO_FILE,
			$bag['size'][ $field_name ] ?? 0
		);
		if ( UPLOAD_ERR_NO_FILE !== $slot['error'] ) {
			$files[] = $slot;
		}

		return $files;
	}

	/**
	 * Process every file field of the submitted form.
	 *
	 * @param array{clean: array<string, mixed>, errors: array<string, string>} $result
	 * @param array<string, mixed> $definition Located form definition.
	 * @return array{clean: array<string, mixed>, errors: array<string, string>}
	 */
	public function process( array $result, array $definition ): array {
		$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] ) ? $definition['fields'] : [];

		foreach ( $fields as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'file' ) {
				continue;
			}
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$pending = ( $result['clean'][ $name ] ?? '' ) === self::PENDING;
			if ( ! $pending ) {
				// No file / field stripped by conditional logic — make sure
				// the sentinel never leaks into persistence regardless.
				if ( isset( $result['clean'][ $name ] ) && self::PENDING === $result['clean'][ $name ] ) {
					$result['clean'][ $name ] = '';
				}
				continue;
			}

			// Other fields failed validation: skip the move (the visitor
			// must re-select the file on the re-rendered form) so failed
			// rounds never leave orphaned files behind.
			if ( ! empty( $result['errors'] ) ) {
				$result['clean'][ $name ] = '';
				continue;
			}

			$outcome = $this->handle_field( $name, $field );
			if ( '' !== $outcome['error'] ) {
				$result['errors'][ $name ] = $outcome['error'];
				$result['clean'][ $name ]  = '';
			} else {
				// Single-file fields persist a string (backwards compatible),
				// multi-file fields an array of URLs — the same shape split
				// the core already uses for checkbox groups.
				$result['clean'][ $name ] = ! empty( $field['multiple'] )
					? $outcome['urls']
					: ( $outcome['urls'][0] ?? '' );
			}
		}

		return $result;
	}

	/**
	 * Validate and store every uploaded file of one field.
	 *
	 * Fails atomically: if any file is rejected, files already moved in
	 * this round are unlinked again so a half-uploaded set never persists.
	 *
	 * @param string               $field_name
	 * @param array<string, mixed> $field Field definition.
	 * @return array{urls: array<int, string>, error: string}
	 */
	private function handle_field( string $field_name, array $field ): array {
		$label = (string) ( $field['label'] ?? $field_name );
		$files = self::incoming_files( $field_name );

		if ( empty( $files ) ) {
			/* translators: %s: field label */
			return [ 'urls' => [], 'error' => sprintf( __( '%s could not be uploaded. Please try again.', 'flinkform-pro' ), $label ) ];
		}

		$max_files = ! empty( $field['multiple'] ) && isset( $field['maxFiles'] ) && is_numeric( $field['maxFiles'] )
			? max( 1, (int) $field['maxFiles'] )
			: 1;

		if ( count( $files ) > $max_files ) {
			return [
				'urls'  => [],
				'error' => sprintf(
					/* translators: 1: field label, 2: maximum number of files */
					__( '%1$s accepts up to %2$d files.', 'flinkform-pro' ),
					$label,
					$max_files
				),
			];
		}

		$urls = [];
		foreach ( $files as $file ) {
			$outcome = $this->handle_single( $file, $field );
			if ( '' !== $outcome['error'] ) {
				// Roll back files already stored in this round.
				foreach ( $urls as $stored_url ) {
					$stored_path = self::url_to_path( $stored_url );
					if ( '' !== $stored_path ) {
						wp_delete_file( $stored_path );
					}
				}
				return [ 'urls' => [], 'error' => $outcome['error'] ];
			}
			$urls[] = $outcome['url'];
		}

		return [ 'urls' => $urls, 'error' => '' ];
	}

	/**
	 * Validate and store one uploaded file.
	 *
	 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file One normalised $_FILES slot.
	 * @param array<string, mixed> $field Field definition (label, allowedTypes, maxSizeMb).
	 * @return array{url: string, error: string}
	 */
	private function handle_single( array $file, array $field ): array {
		$label = (string) ( $field['label'] ?? (string) ( $field['name'] ?? '' ) );

		if ( UPLOAD_ERR_OK !== $file['error'] || '' === $file['tmp_name'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			/* translators: %s: field label */
			return $this->fail( __( '%s could not be uploaded. Please try again.', 'flinkform-pro' ), $label );
		}

		// Size: per-field author cap, clamped to the server limit.
		$max_mb    = isset( $field['maxSizeMb'] ) && is_numeric( $field['maxSizeMb'] ) ? max( 1, (int) $field['maxSizeMb'] ) : 5;
		$max_bytes = min( $max_mb * MB_IN_BYTES, (int) wp_max_upload_size() );
		if ( $file['size'] <= 0 || $file['size'] > $max_bytes ) {
			return [
				'url'   => '',
				'error' => sprintf(
					/* translators: 1: field label, 2: maximum size in MB */
					__( '%1$s exceeds the maximum file size of %2$d MB.', 'flinkform-pro' ),
					$label,
					(int) floor( $max_bytes / MB_IN_BYTES )
				),
			];
		}

		// Build the mime allow-list from the author's selection.
		$allowed_exts = isset( $field['allowedTypes'] ) && is_array( $field['allowedTypes'] ) ? $field['allowedTypes'] : [];
		$mimes        = [];
		foreach ( $allowed_exts as $ext ) {
			$ext = sanitize_key( (string) $ext );
			if ( isset( self::TYPE_MAP[ $ext ] ) ) {
				$mimes += self::TYPE_MAP[ $ext ];
			}
		}
		if ( empty( $mimes ) ) {
			/* translators: %s: field label */
			return $this->fail( __( '%s does not accept any file type.', 'flinkform-pro' ), $label );
		}

		// Extension + content sniff. A mismatch (renamed executable, wrong
		// extension) yields no ext/type and is rejected.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			/* translators: %s: field label */
			return $this->fail( __( '%s must be one of the allowed file types.', 'flinkform-pro' ), $label );
		}

		// Move into uploads/flinkform/<Y>/<m>/ with a randomised name.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		add_filter( 'upload_dir', [ self::class, 'filter_upload_dir' ] );
		$moved = wp_handle_upload(
			$file,
			[
				'test_form'                => false,
				'mimes'                    => $mimes,
				'unique_filename_callback' => [ self::class, 'randomise_filename' ],
			]
		);
		remove_filter( 'upload_dir', [ self::class, 'filter_upload_dir' ] );

		if ( ! is_array( $moved ) || empty( $moved['url'] ) || ! empty( $moved['error'] ) ) {
			/* translators: %s: field label */
			return $this->fail( __( '%s could not be stored on the server.', 'flinkform-pro' ), $label );
		}

		return [
			'url'   => esc_url_raw( (string) $moved['url'] ),
			'error' => '',
		];
	}

	/**
	 * sprintf the label into an error template.
	 *
	 * @param string $template Template with one %s.
	 * @param string $label    Field label.
	 * @return array{url: string, error: string}
	 */
	private function fail( string $template, string $label ): array {
		return [
			'url'   => '',
			'error' => sprintf( $template, $label ),
		];
	}

	/**
	 * upload_dir filter — divert into the protected flinkform subdirectory
	 * while preserving the Y/m sharding.
	 *
	 * @param array<string, mixed> $dirs
	 * @return array<string, mixed>
	 */
	public static function filter_upload_dir( array $dirs ): array {
		$subdir = '/' . self::SUBDIR . (string) $dirs['subdir'];

		$dirs['subdir'] = $subdir;
		$dirs['path']   = $dirs['basedir'] . $subdir;
		$dirs['url']    = $dirs['baseurl'] . $subdir;

		self::ensure_protection( (string) $dirs['basedir'] );

		return $dirs;
	}

	/**
	 * Randomised stored filename: 16 hex chars + the sanitised original.
	 * Keeps the human-readable name visible while making URLs
	 * non-enumerable.
	 *
	 * @param string $dir  Target directory (unused).
	 * @param string $name Original filename.
	 * @param string $ext  Extension including the dot.
	 * @return string
	 */
	public static function randomise_filename( $dir, $name, $ext ): string {
		$base = sanitize_file_name( pathinfo( (string) $name, PATHINFO_FILENAME ) );
		$base = '' !== $base ? substr( $base, 0, 80 ) : 'file';

		try {
			$random = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$random = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}

		return $random . '-' . $base . (string) $ext;
	}

	/**
	 * Drop an execution-blocking .htaccess + index.html into the flinkform
	 * uploads root (once).
	 *
	 * @param string $basedir Uploads base directory.
	 * @return void
	 */
	private static function ensure_protection( string $basedir ): void {
		$root = $basedir . '/' . self::SUBDIR;

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		$htaccess = $root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Flinkform uploads - block script execution\n"
				. "<IfModule mod_rewrite.c>\nRewriteEngine Off\n</IfModule>\n"
				. "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps|pht|cgi|pl|py|asp|aspx|sh)$\">\n"
				. "\tRequire all denied\n"
				. "</FilesMatch>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file in our own uploads subdir.
			file_put_contents( $htaccess, $rules );
		}

		$index = $root . '/index.html';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file in our own uploads subdir.
			file_put_contents( $index, '' );
		}
	}

	/**
	 * Resolve an uploaded-file URL back to its absolute path — strictly
	 * contained inside the flinkform uploads subdirectory.
	 *
	 * @param string $url
	 * @return string Absolute path, or '' when the URL is not one of ours.
	 */
	public static function url_to_path( string $url ): string {
		$uploads = wp_upload_dir( null, false );
		$baseurl = (string) $uploads['baseurl'];
		$basedir = (string) $uploads['basedir'];

		$prefix = $baseurl . '/' . self::SUBDIR . '/';
		if ( 0 !== strpos( $url, $prefix ) ) {
			return '';
		}

		$relative = substr( $url, strlen( $prefix ) );
		// No traversal segments in a URL we minted, ever — reject anyway.
		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		$path = $basedir . '/' . self::SUBDIR . '/' . $relative;
		return file_exists( $path ) ? $path : '';
	}
}
