=== NC Migration ===
Contributors: nicholas-cod3r
Tags: migration, sync, database, files
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Same-server WordPress file and database migration tools.

== Description ==

NC Migration copies files and WordPress-prefixed database tables from the current site to another path and URL on the same server. It is available under Tools → Migration for users with the manage_options capability.

Configuration is a JSON file in the WordPress web root (`migration-config.json`). Copy `migration-config.json.sample` from this plugin and adjust source path, targets, and exclusions.

This plugin is distributed from GitHub Releases, not wordpress.org. Updates use the Release ZIP asset.

== Installation ==

1. Download nc-migration.zip from the GitHub Releases page. Do not use Source code (zip).
2. Upload the ZIP in WP Admin → Plugins → Add Plugin, or extract it to wp-content/plugins/nc-migration/.
3. Activate NC Migration.
4. Place migration-config.json in the WordPress root (see the sample file in the plugin).
5. Open Tools → Migration.

== Frequently Asked Questions ==

= Where is the configuration file? =

In the WordPress root, next to wp-config.php: `migration-config.json`. The plugin directory only contains a sample.

= How do updates work? =

Install from the GitHub Release nc-migration.zip asset. After that, WordPress can show updates and automatic updates from later Release ZIPs.

== Changelog ==

= 1.0.1 =
* First public GitHub Release.

== Upgrade Notice ==

= 1.0.1 =
First public release. Install from the GitHub Release ZIP.
