<?php
/**
 * Migration admin page view.
 *
 * @package NC\Migration
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap migration-admin-wrap">
	<h1><?php echo esc_html__( 'Migrate site', 'nc-migration' ); ?></h1>
	<?php if ( empty( $export_htaccess_protected ) ) : ?>
		<div class="notice notice-warning inline" id="migration-htaccess-warning">
			<p>
				<?php echo esc_html__( 'Warning: The migration-config.json and export.sql.gz are not blocked in .htaccess.', 'nc-migration' ); ?>
				<button type="button" class="button button-secondary button-small" id="migration-protect-export">
					<?php echo esc_html__( 'Add rule', 'nc-migration' ); ?>
				</button>
			</p>
		</div>
	<?php endif; ?>
	<div class="migration-admin-top">
		<div class="migration-admin-controls">
			<h2><?php echo esc_html__( 'Options', 'nc-migration' ); ?></h2>
			<div class="migration-toolbar">
				<span>
					<button type="button" id="analyze" disabled><?php echo esc_html__( 'Analyze files', 'nc-migration' ); ?></button>
					<button type="button" id="migrate" disabled><?php echo esc_html__( 'Migrate files', 'nc-migration' ); ?></button>
					<button type="button" id="transferdb" disabled><?php echo esc_html__( 'Transfer database', 'nc-migration' ); ?></button>
					<button type="button" id="clear"><?php echo esc_html__( 'Clear log', 'nc-migration' ); ?></button>
				</span>
				<div id="progress-div" class="progress" style="display:none">
					<span class="progress-label"><?php echo esc_html__( 'Progress:', 'nc-migration' ); ?> </span>
					<span id="progress-counter" class="progress-label">0/0</span>
					<progress id="progress-bar" class="migration-progress" max="100" value="0"></progress>
					<span id="progress-percentage" class="progress-label">0 %</span>
					<span><button type="button" id="stopmigrate" disabled><?php echo esc_html__( 'Stop', 'nc-migration' ); ?></button></span>
				</div>
			</div>
			<h2><?php echo esc_html__( 'Parameters', 'nc-migration' ); ?></h2>
			<label for="targets"><?php echo esc_html__( 'Selected target:', 'nc-migration' ); ?> </label>
			<select name="targets" id="targets" disabled></select>
		</div>
		<div class="migration-params-div">
			<div class="migration-params-column migration-params-source">
				<h3><?php echo esc_html__( 'Source', 'nc-migration' ); ?></h3>
				<p>
					<span><?php echo esc_html__( 'Path:', 'nc-migration' ); ?> </span><span id="SourcePath"></span><br />
					<span><?php echo esc_html__( 'URL:', 'nc-migration' ); ?> </span><span id="SourceUrl"></span><br />
				</p>
			</div>
			<div class="migration-params-column migration-params-target">
				<h3><?php echo esc_html__( 'Target', 'nc-migration' ); ?></h3>
				<p>
					<span><?php echo esc_html__( 'Path:', 'nc-migration' ); ?> </span><span id="TargetPath"></span><br />
					<span><?php echo esc_html__( 'URL:', 'nc-migration' ); ?> </span><span id="TargetUrl"></span><br />
				</p>
			</div>
		</div>
	</div>
	<div class="migration-log">
		<h2><?php echo esc_html__( 'Log', 'nc-migration' ); ?></h2>
		<div class="result" id="result"></div>
	</div>
</div>
