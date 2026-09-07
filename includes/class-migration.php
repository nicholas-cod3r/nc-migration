<?php
/**
 * Migration module bootstrap: admin UI, REST API, assets.
 *
 * @package NC\Migration
 */

declare(strict_types=1);

namespace NC\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main migration controller.
 */
class Migration {

	public const REST_NAMESPACE = 'nc-migration/v1';

	public const ROUTE_ANALYZE         = 'analyze';
	public const ROUTE_COPY            = 'copy';
	public const ROUTE_EXPORTDB        = 'exportdb';
	public const ROUTE_PROTECT_EXPORT  = 'protect-export';

	public const HTACCESS_MARKER = 'Migration export protect';

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Bootstrap hooks.
	 */
	public static function init(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		self::$instance->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Load translations from /languages.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'nc-migration',
			false,
			dirname( plugin_basename( NC_MIGRATION_DIR . 'nc-migration.php' ) ) . '/languages'
		);
	}

	/**
	 * Tools → Migration menu.
	 */
	public function register_admin_menu(): void {
		$this->page_hook = (string) add_management_page(
			__( 'Migrate site', 'nc-migration' ),
			__( 'Migration', 'nc-migration' ),
			'manage_options',
			'nc-migration',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page view.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nc-migration' ) );
		}

		$export_htaccess_protected = $this->is_export_file_htaccess_protected();
		include NC_MIGRATION_DIR . 'views/admin-page.php';
	}

	/**
	 * Whether ABSPATH/.htaccess has a Files/FilesMatch block that both
	 * targets migration-config.json + export.sql.gz and denies access.
	 *
	 * @return bool
	 */
	private function is_export_file_htaccess_protected(): bool {
		$htaccess = ABSPATH . '.htaccess';
		if ( ! is_readable( $htaccess ) ) {
			return false;
		}

		$content = file_get_contents( $htaccess );
		if ( false === $content || '' === $content ) {
			return false;
		}

		if ( ! preg_match_all(
			'/<Files(?:Match)?\b[^>]*>.*?<\/Files(?:Match)?>/is',
			$content,
			$blocks
		) ) {
			return false;
		}

		foreach ( $blocks[0] as $block ) {
			// Exact names only — avoid substring hits like "mmigration-config.json".
			$mentions_export = (bool) preg_match(
				'/(?<![A-Za-z0-9_-])export\\\\?\\.sql\\\\?\\.gz(?![A-Za-z0-9_-])/i',
				$block
			);
			$mentions_config = (bool) preg_match(
				'/(?<![A-Za-z0-9_-])migration-config\\\\?\\.json(?![A-Za-z0-9_-])/i',
				$block
			);
			if ( ! $mentions_export || ! $mentions_config ) {
				continue;
			}

			// Apache 2.4.
			if ( preg_match( '/Require\s+all\s+denied/i', $block ) ) {
				return true;
			}

			// Apache 2.2: Deny from all needs an Order directive in the same block.
			$has_deny  = (bool) preg_match( '/Deny\s+from\s+all/i', $block );
			$has_order = (bool) preg_match( '/Order\s+Allow\s*,\s*Deny/i', $block );
			if ( $has_deny && $has_order ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Append a deny rule for migration-config.json and export.sql.gz to ABSPATH/.htaccess.
	 *
	 * @return true|\WP_Error
	 */
	private function protect_export_in_htaccess() {
		if ( $this->is_export_file_htaccess_protected() ) {
			return true;
		}

		$htaccess = ABSPATH . '.htaccess';
		$existing = '';
		if ( file_exists( $htaccess ) ) {
			if ( ! is_readable( $htaccess ) || ! is_writable( $htaccess ) ) {
				return new \WP_Error( 'htaccess_not_writable', __( '.htaccess is not writable.', 'nc-migration' ) );
			}
			$read = file_get_contents( $htaccess );
			if ( false === $read ) {
				return new \WP_Error( 'htaccess_read_failed', __( 'Failed to read .htaccess.', 'nc-migration' ) );
			}
			$existing = $read;

			$backup = $this->backup_htaccess( $htaccess, $existing );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
		} elseif ( ! is_writable( ABSPATH ) ) {
			return new \WP_Error( 'abspath_not_writable', __( 'Site root is not writable; cannot create .htaccess.', 'nc-migration' ) );
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$insertion = array(
			'<FilesMatch "^(migration-config\.json|export\.sql\.gz)$">',
			"\t<IfModule mod_authz_core.c>",
			"\t\tRequire all denied",
			"\t</IfModule>",
			"\t<IfModule !mod_authz_core.c>",
			"\t\tOrder Allow,Deny",
			"\t\tDeny from all",
			"\t</IfModule>",
			'</FilesMatch>',
		);

		$written = insert_with_markers( $htaccess, self::HTACCESS_MARKER, $insertion );
		if ( ! $written ) {
			return new \WP_Error( 'htaccess_write_failed', __( 'Failed to write .htaccess.', 'nc-migration' ) );
		}

		return true;
	}

	/**
	 * Copy .htaccess to .htaccess.bak.N (next free N starting at 1).
	 *
	 * @param string $htaccess Path to .htaccess.
	 * @param string $contents Current file contents.
	 * @return string|\WP_Error Backup path on success.
	 */
	private function backup_htaccess( string $htaccess, string $contents ) {
		$timestamp = gmdate( 'Ymd-His' );
		$backup = ABSPATH . '.htaccess.' . $timestamp . '.bak';

		if ( false === file_put_contents( $backup, $contents ) ) {
			return new \WP_Error( 'htaccess_backup_failed', __( 'Failed to create .htaccess backup.', 'nc-migration' ) );
		}

		return $backup;
	}

	/**
	 * Enqueue admin assets on the migration screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		$css_rel = '/assets/migrate-admin.css';
		$js_rel  = '/assets/migrate.js';
		$css_path = NC_MIGRATION_DIR . $css_rel;
		$js_path  = NC_MIGRATION_DIR . $js_rel;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'nc-migration-admin',
				NC_MIGRATION_URI . $css_rel,
				array(),
				(string) filemtime( $css_path )
			);
		}

		wp_enqueue_script(
			'nc-migration-admin',
			NC_MIGRATION_URI . $js_rel,
			array( 'jquery' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0',
			true
		);

		$config_loader = new Migration_Config();
		$config_loader->load();

		wp_localize_script(
			'nc-migration-admin',
			'ncMigrationAdmin',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'urls'         => array(
					'analyze'        => rest_url( self::REST_NAMESPACE . '/' . self::ROUTE_ANALYZE ),
					'copy'           => rest_url( self::REST_NAMESPACE . '/' . self::ROUTE_COPY ),
					'exportdb'       => rest_url( self::REST_NAMESPACE . '/' . self::ROUTE_EXPORTDB ),
					'protectExport'  => rest_url( self::REST_NAMESPACE . '/' . self::ROUTE_PROTECT_EXPORT ),
				),
				'config'       => $config_loader->get_config(),
				'configErrors' => $config_loader->get_errors(),
				'sourceUrl'    => get_bloginfo( 'wpurl' ),
				'debug'        => 'local' === wp_get_environment_type(),
				'i18n'         => array(
					'clearConfirm'   => __( 'Do you really want to clear the results?', 'nc-migration' ),
					'migrateConfirm' => __( "This will copy new and updated files from:\n  %1\$s\nto:\n  %2\$s\n\nWARNING! Existing files will be overwritten!\nContinue?", 'nc-migration' ),
					'dbConfirm'      => __( "This will copy Wordpress related database tables from current instance to \n  '%s' database.\nWARNING! Already existing tables will be dropped irreversibly.\nContinue?", 'nc-migration' ),
					'protectFailed'  => __( 'Failed to update .htaccess.', 'nc-migration' ),
				),
			)
		);
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes(): void {
		$permission = array( $this, 'rest_permission_check' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::ROUTE_ANALYZE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_analyze' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::ROUTE_COPY,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_copy' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::ROUTE_EXPORTDB,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_exportdb' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::ROUTE_PROTECT_EXPORT,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_protect_export' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * @return bool
	 */
	public function rest_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Analyze filesystem differences, one time-boxed batch per call.
	 *
	 * The client re-submits the 'cursor' from the previous response until Analyze_Result::$done
	 * comes back true; see Migration_File_Sync::scan_batch(). The cursor is treated the same as
	 * the 'files' param in rest_copy(): it comes from the browser and is re-validated server-side.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_analyze( \WP_REST_Request $request ): \WP_REST_Response {
		$result = new Analyze_Result();
		$config = null;
		$target = $this->resolve_target( $request, $result, $config );
		if ( false === $target ) {
			return rest_ensure_response( $result );
		}

		$cursor_raw = $request->get_param( 'cursor' );
		if ( is_string( $cursor_raw ) && '' !== $cursor_raw ) {
			$cursor = json_decode( $cursor_raw, true );
		} else {
			$cursor = $cursor_raw;
		}
		if ( ! is_array( $cursor ) ) {
			$cursor = null;
		}

		$file_sync = new Migration_File_Sync();
		$result    = $file_sync->scan_batch(
			(string) $config->Source->Path,
			(string) $target->Path,
			(array) $config->ExcludedFiles,
			(array) $config->ExcludedFolders,
			$cursor
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Copy a batch of files.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_copy( \WP_REST_Request $request ): \WP_REST_Response {
		$result = new Migrate_Result();
		$config = null;
		$target = $this->resolve_target( $request, $result, $config );
		if ( false === $target ) {
			return rest_ensure_response( $result );
		}

		try {
			$files_raw = $request->get_param( 'files' );
			if ( is_string( $files_raw ) ) {
				$files = json_decode( $files_raw, true );
			} else {
				$files = $files_raw;
			}
			if ( ! is_array( $files ) ) {
				$files = array();
			}

			$files_as_object = array();
			foreach ( $files as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$file         = new Migrate_File();
				$file->name   = isset( $f['name'] ) ? (string) $f['name'] : '';
				$file->path   = isset( $f['path'] ) ? (string) $f['path'] : '';
				$file->MTime  = isset( $f['MTime'] ) ? (int) $f['MTime'] : 0;
				$file->status = isset( $f['status'] ) ? (string) $f['status'] : '';
				$files_as_object[] = $file;
			}

			$file_sync = new Migration_File_Sync();
			$result    = $file_sync->update_files(
				$files_as_object,
				(string) $config->Source->Path,
				(string) $target->Path,
				(array) $config->ExcludedFiles,
				(array) $config->ExcludedFolders
			);
		} catch ( \Exception $e ) {
			$result->return_code    = -1;
			$result->return_message = $e->getMessage();
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Dump/import database and replace URLs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_exportdb( \WP_REST_Request $request ): \WP_REST_Response {
		$result = new DB_Result();
		$target = $this->resolve_target( $request, $result );
		if ( false === $target ) {
			return rest_ensure_response( $result );
		}

		$database = new Migration_Database();
		$result   = $database->transfer( $target );

		return rest_ensure_response( $result );
	}

	/**
	 * Append deny rule for export.sql.gz to .htaccess.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_protect_export( \WP_REST_Request $request ) {
		unset( $request );
		$result = $this->protect_export_in_htaccess();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'return_code'    => 0,
				'return_message' => 'OK',
				'protected'      => true,
			)
		);
	}

	/**
	 * Load config and resolve target from the request.
	 *
	 * @param \WP_REST_Request                        $request  Request.
	 * @param Analyze_Result|Migrate_Result|DB_Result $response Response DTO to fill on error.
	 * @param object|null                             $config   Receives the validated config on success.
	 * @return object|false
	 */
	private function resolve_target( \WP_REST_Request $request, $response, ?object &$config = null ) {
		$config = null;

		$config_loader = new Migration_Config();
		if ( ! $config_loader->load() ) {
			$response->return_code    = -100;
			$response->return_message = 'Server migration configuration invalid';
			return false;
		}

		$target_id = $request->get_param( 'targetID' );
		$target    = $config_loader->get_target( $target_id );
		if ( false === $target ) {
			$response->return_code    = -101;
			$response->return_message = "Requested target ID $target_id not found in configuration";
			return false;
		}

		$config = $config_loader->get_config();

		return $target;
	}
}
