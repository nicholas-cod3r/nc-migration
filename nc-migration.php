<?php
/**
 * Plugin Name: NC Migration
 * Plugin URI: https://github.com/nicholas-cod3r/nc-migration
 * Description: Same-server WordPress file and database migration tools.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: nicholas-cod3r
 * Author URI: https://github.com/nicholas-cod3r
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/nicholas-cod3r/nc-migration
 * Text Domain: nc-migration
 * Domain Path: /languages
 *
 * @package NC\Migration
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NC_MIGRATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'NC_MIGRATION_URI', plugin_dir_url( __FILE__ ) );

// PUC must load on admin and WP-Cron (cron often runs via frontend wp-cron.php).
if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
	require_once NC_MIGRATION_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	$nc_migration_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/nicholas-cod3r/nc-migration/',
		__FILE__,
		'nc-migration'
	);
	$nc_migration_update_checker->getVcsApi()->enableReleaseAssets( '/nc-migration\\.zip($|[?&#])/i' );
}

// Front: skip. Migrate REST: only when a logged-in auth cookie is present (admin UI calls).
if ( ! is_admin() ) {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

	$is_pretty_migrate_rest = false !== strpos( $uri, '/wp-json/nc-migration/' );
	$is_plain_migrate_rest  = false !== strpos( $uri, 'rest_route=/nc-migration/' );
	$is_migrate_rest        = $is_pretty_migrate_rest || $is_plain_migrate_rest;

	if ( ! $is_migrate_rest ) {
		return;
	}

	$has_auth_cookie = false;
	foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
		if ( 0 === strpos( (string) $cookie_name, 'wordpress_logged_in_' ) ) {
			$has_auth_cookie = true;
			break;
		}
	}
	if ( ! $has_auth_cookie ) {
		return;
	}
}

require_once NC_MIGRATION_DIR . 'vendor/mysqldump/MySQLDump.php';
require_once NC_MIGRATION_DIR . 'vendor/mysqldump/MySQLImport.php';
require_once NC_MIGRATION_DIR . 'includes/class-migration-results.php';
require_once NC_MIGRATION_DIR . 'includes/class-migration-config.php';
require_once NC_MIGRATION_DIR . 'includes/class-migration-file-sync.php';
require_once NC_MIGRATION_DIR . 'includes/class-migration-database.php';
require_once NC_MIGRATION_DIR . 'includes/class-migration.php';

\NC\Migration\Migration::init();
