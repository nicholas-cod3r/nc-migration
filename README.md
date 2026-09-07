# NC Migration

Same-server WordPress file and database migration. After activation it appears under **Tools → Migration**.

Requires WordPress 6.0+ and PHP 7.4+. Configuration lives in `migration-config.json` in the site web root (`ABSPATH`), not inside the plugin.

## Install

Use the GitHub Release asset **`nc-migration.zip`**, not “Source code (zip)”. The source archive uses a versioned folder name and will not install as `nc-migration/`.

1. Download `nc-migration.zip` from [Releases](https://github.com/nicholas-cod3r/nc-migration/releases).
2. In WP Admin: Plugins → Add Plugin → Upload Plugin, or unzip into `wp-content/plugins/nc-migration/`.
3. Activate **NC Migration**.
4. Copy [`migration-config.json.sample`](migration-config.json.sample) to `migration-config.json` in the WordPress root and edit source/target paths.

`git clone` is for development only. Do not run a git checkout as the production plugin folder: WordPress updates overwrite that directory.

## Updates

Later versions come from the same Release ZIP via Plugin Update Checker. In Plugins you can click **Update now** or enable **Automatic updates**.

The first switch from an older copy that had no updater is a one-time install of a Release ZIP. After that, WordPress handles updates.

## Release (maintainers)

Version numbers are not bumped by hand. In the GitHub repo: **Actions → Release → Run workflow**, choose `patch`, `minor`, or `major`.

The workflow updates `Version` in `nc-migration.php` and `Stable tag` in `readme.txt`, commits, tags `vX.Y.Z`, and attaches `nc-migration.zip` to the GitHub Release.

## License

GPL v2 or later. Vendored libraries: [MySQLDump](vendor/mysqldump/MySQLDump.php) (New BSD), [Plugin Update Checker](vendor/plugin-update-checker/license.txt) (MIT).
