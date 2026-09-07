<?php
/**
 * Migration configuration loader and validator.
 *
 * @package NC\Migration
 */

declare(strict_types=1);

namespace NC\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and validates migration-config.json.
 */
class Migration_Config {

	/**
	 * Absolute path to the live config file.
	 *
	 * @var string
	 */
	private $config_file;

	/**
	 * Parsed config object, or null on failure.
	 *
	 * @var object|null
	 */
	private $config = null;

	/**
	 * Validation / load errors.
	 *
	 * @var array<int, string>
	 */
	private $errors = array();

	/**
	 * @param string|null $config_file Absolute path; defaults to ABSPATH/migration-config.json.
	 */
	public function __construct( ?string $config_file = null ) {
		$this->config_file = $config_file ?? ( ABSPATH . 'migration-config.json' );
	}

	/**
	 * Load and parse the configuration file.
	 *
	 * @return bool True when config is valid.
	 */
	public function load(): bool {
		$this->errors = array();
		$this->config = null;

		$file = @file_get_contents( $this->config_file );
		if ( false === $file ) {
			$last = error_get_last();
			$message = is_array( $last ) && isset( $last['message'] ) ? $last['message'] : 'Unable to read config file';
			$this->errors[] = 'Failed to load migration configuration: ' . $message;
			return false;
		}

		$decoded = json_decode( $file );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->errors[] = 'Failed to load migration configuration: JSON parse error code: ' . json_last_error();
			return false;
		}

		$this->config = $decoded;
		$this->parse();

		if ( count( $this->errors ) > 0 ) {
			array_unshift( $this->errors, 'Migration configuration invalid, details:' );
			return false;
		}

		return true;
	}

	/**
	 * @return object|null
	 */
	public function get_config(): ?object {
		return $this->config;
	}

	/**
	 * @return array<int, string>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Resolve a target by numeric index.
	 *
	 * @param int|string $target_id Target index.
	 * @return object|false
	 */
	public function get_target( $target_id ) {
		if ( null === $this->config || ! isset( $this->config->Targets ) || ! is_array( $this->config->Targets ) ) {
			return false;
		}

		$index = (int) $target_id;
		if ( ! array_key_exists( $index, $this->config->Targets ) ) {
			return false;
		}

		$target = $this->config->Targets[ $index ];
		return is_object( $target ) ? $target : false;
	}

	/**
	 * Generic sample configuration array.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_sample_settings(): array {
		return array(
			'_description01'   => 'Migration configuration. Copy files and database from the source instance to another path/URL on the same server.',
			'_description02'   => 'Available under Tools → Migration for users with manage_options capability.',
			'_description03'   => 'Both source and target directories must contain wp-config.php (used to read DB credentials).',
			'Source'           => array(
				'_description' => "Physical path of the source site (wp-config.php location). Leave as '%SERVER_ROOT%' for the web root.",
				'Path'         => '%SERVER_ROOT%',
			),
			'Targets'          => array(
				array(
					'Name' => 'Local copy',
					'Path' => '%SERVER_ROOT%/../copy',
					'URL'  => 'http://localhost/copy',
				),
				array(
					'Name' => 'Staging',
					'Path' => '%SERVER_ROOT%/../staging',
					'URL'  => 'https://staging.example.com',
				),
			),
			'_description04'   => 'Excluded file name patterns. Wildcards * and ? are allowed.',
			'_description05'   => 'These patterns apply in every directory.',
			'ExcludedFiles'    => array(
				'wp-config.php',
				'.htaccess',
				'googled*.html',
				'robots.txt',
				'migration-config.json',
				'export.sql.gz',
			),
			'_description06'   => 'Excluded folders. Wildcards are NOT supported.',
			'_description07'   => 'Paths are relative to the source path.',
			'_description08'   => 'Paths must end with /.',
			'ExcludedFolders'  => array(
				'wp-content/cache/',
				'wp-content/upgrade/',
			),
		);
	}

	/**
	 * Write sample config next to the live config path.
	 *
	 * @return bool
	 */
	public function write_sample_file(): bool {
		$data = wp_json_encode( self::get_sample_settings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $data ) {
			return false;
		}
		return false !== file_put_contents( $this->config_file . '.sample', $data );
	}

	/**
	 * Validate and normalize the loaded config.
	 */
	private function parse(): void {
		if ( ! isset( $this->config->Source ) ) {
			$this->errors[] = 'Missing [Source] node';
		} elseif ( ! isset( $this->config->Source->Path ) ) {
			$this->errors[] = 'Missing Source/Path parameter';
		} else {
			$this->config->Source->Path = $this->canonicalize_path(
				$this->fill_placeholder( (string) $this->config->Source->Path )
			);
		}

		if ( ! isset( $this->config->Targets ) || ! is_array( $this->config->Targets ) ) {
			$this->errors[] = 'Missing or invalid [Targets] node';
		} elseif ( 0 === count( $this->config->Targets ) ) {
			$this->errors[] = 'No Target defined, minimum 1 is required';
		} else {
			$i = 1;
			foreach ( $this->config->Targets as $target ) {
				if ( ! isset( $target->Name ) ) {
					$this->errors[] = "Target $i is missing 'Name' parameter";
				}
				if ( isset( $target->Path ) ) {
					$target->Path = $this->canonicalize_path(
						$this->fill_placeholder( (string) $target->Path )
					);
				} else {
					$this->errors[] = "Target $i is missing 'Path' parameter";
				}
				if ( ! isset( $target->URL ) ) {
					$this->errors[] = "Target $i is missing 'URL' parameter";
				}
				++$i;
			}
		}

		if ( ! isset( $this->config->ExcludedFiles ) || ! is_array( $this->config->ExcludedFiles ) ) {
			$this->errors[] = 'Missing or invalid [ExcludedFiles] node';
		}

		if ( ! isset( $this->config->ExcludedFolders ) || ! is_array( $this->config->ExcludedFolders ) ) {
			$this->errors[] = 'Missing or invalid [ExcludedFolders] node';
		} else {
			foreach ( $this->config->ExcludedFolders as &$folder ) {
				$folder = (string) $folder;
				$len    = strlen( $folder );
				if ( $len > 0 && '/' !== $folder[ $len - 1 ] ) {
					$folder .= '/';
				}
			}
			unset( $folder );
		}
	}

	/**
	 * @param string $value Raw config value.
	 * @return string
	 */
	private function fill_placeholder( string $value ): string {
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : ABSPATH;
		return str_replace( '%SERVER_ROOT%', $document_root, $value );
	}

	/**
	 * Resolve a filesystem path to a canonical form for display and operations.
	 *
	 * Uses realpath() when the path exists. Otherwise collapses `.` / `..`
	 * segments so a missing target still shows a readable absolute path.
	 *
	 * @param string $path Placeholder-expanded path.
	 * @return string
	 */
	private function canonicalize_path( string $path ): string {
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return $path;
		}

		$real = realpath( $path );
		if ( false !== $real ) {
			return wp_normalize_path( $real );
		}

		return $this->resolve_dot_segments( wp_normalize_path( $path ) );
	}

	/**
	 * Collapse `.` and `..` without requiring the path to exist.
	 *
	 * @param string $path Path already passed through wp_normalize_path().
	 * @return string
	 */
	private function resolve_dot_segments( string $path ): string {
		$drive       = '';
		$is_absolute = ( '' !== $path && '/' === $path[0] );

		if ( preg_match( '#^([A-Za-z]:)(/.*)?$#', $path, $matches ) ) {
			$drive       = $matches[1];
			$path        = isset( $matches[2] ) ? $matches[2] : '';
			$is_absolute = true;
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( count( $segments ) > 0 ) {
					array_pop( $segments );
				} elseif ( ! $is_absolute ) {
					$segments[] = '..';
				}
				continue;
			}
			$segments[] = $segment;
		}

		$resolved = implode( '/', $segments );

		if ( '' !== $drive ) {
			return rtrim( $drive . '/' . $resolved, '/' );
		}

		if ( $is_absolute ) {
			return '/' . $resolved;
		}

		return $resolved;
	}
}
