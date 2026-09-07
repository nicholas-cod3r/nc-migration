<?php
/**
 * Migration file analyze and copy.
 *
 * @package NC\Migration
 */

declare(strict_types=1);

namespace NC\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filesystem sync helpers for migration.
 */
class Migration_File_Sync {

	/**
	 * Scan the source tree for differences against the destination, in time-boxed batches.
	 *
	 * Each call walks the source directory-by-directory (breadth order) for up to
	 * $time_budget_seconds, comparing every file it finds against the destination's mtime
	 * as it goes — nothing is held in memory beyond the current batch of differences and the
	 * queue of directories still to visit. When the budget runs out mid-tree, the remaining
	 * queue is returned as a cursor to resume from on the next call; $result->done tells the
	 * caller whether the whole tree has been covered. A directory that can't be read mid-scan
	 * (removed, renamed, permission denied) is recorded in $result->unreadable_dirs rather than
	 * silently treated as having no differences.
	 *
	 * The cursor is round-tripped through the browser between requests (each REST call is a
	 * fresh PHP process, so there is nothing else to resume from), so every path segment in
	 * it is re-normalized and re-checked against the source root before use; never trust the
	 * submitted cursor.
	 *
	 * @param string                     $source_path         Source root.
	 * @param string                     $destination_path    Target root.
	 * @param array<int, string>         $excluded_files      Filename patterns.
	 * @param array<int, string>         $excluded_folders    Relative folder prefixes.
	 * @param array<string, mixed>|null  $cursor              Cursor from a previous batch, or null to start from the root.
	 * @param int                        $time_budget_seconds Wall-clock budget for this batch.
	 * @return Analyze_Result
	 */
	public function scan_batch(
		string $source_path,
		string $destination_path,
		array $excluded_files = array(),
		array $excluded_folders = array(),
		?array $cursor = null,
		int $time_budget_seconds = 15
	): Analyze_Result {
		$result       = new Analyze_Result();
		$result->done = true;

		$source_root = $this->resolve_root( $source_path );
		if ( false === $source_root ) {
			$result->return_code    = -1;
			$result->return_message = "Source directory not exists: $source_path";
			return $result;
		}

		$destination_root = $this->resolve_root( $destination_path );
		if ( false === $destination_root ) {
			$result->return_code    = -1;
			$result->return_message = "Target directory not exists: $destination_path";
			return $result;
		}

		$pending_dirs = $this->sanitize_pending_dirs( $cursor, $source_root, $result );

		$start_time = time();
		$first_dir  = true;

		// The `|| $first_dir` guards against a zero (or already-elapsed) budget stalling the
		// scan forever: every call must make at least one directory of progress.
		while ( array() !== $pending_dirs && ( $first_dir || time() < $start_time + $time_budget_seconds ) ) {
			$first_dir    = false;
			$relative_dir = array_shift( $pending_dirs );
			$full_dir     = '' === $relative_dir ? $source_root : $source_root . '/' . $relative_dir;

			$entries = @scandir( $full_dir );
			if ( false === $entries ) {
				// Vanished/renamed/permission-denied mid-scan — surface it instead of silently
				// under-reporting differences for whatever was in this directory.
				$result->unreadable_dirs[] = $relative_dir;
				continue;
			}

			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				$entry_relative = '' === $relative_dir ? $name : $relative_dir . '/' . $name;
				$entry_full     = $full_dir . '/' . $name;

				if ( is_dir( $entry_full ) ) {
					// Skip queueing excluded subtrees entirely — nothing under them is ever scanned.
					if ( ! $this->is_directory_excluded( $entry_relative, $excluded_folders ) ) {
						$pending_dirs[] = $entry_relative;
					}
					continue;
				}

				if ( ! is_file( $entry_full ) || $this->is_file_excluded( $name, $excluded_files ) ) {
					continue;
				}

				$this->append_if_changed( $result, $relative_dir, $name, $entry_full, $destination_root . '/' . $entry_relative );
			}
		}

		$result->done            = array() === $pending_dirs;
		$result->cursor          = $result->done ? null : array( 'pending_dirs' => $pending_dirs );
		$result->return_code     = 0;
		$result->return_message  = $result->done ? 'OK' : 'In progress';

		return $result;
	}

	/**
	 * Append a Migrate_File descriptor to the result if the source file is new or modified
	 * relative to the destination.
	 *
	 * @param Analyze_Result $result           Result to append to.
	 * @param string         $relative_dir     Source file's relative directory ('' for the root).
	 * @param string         $name             Source file name.
	 * @param string         $source_file      Full source file path.
	 * @param string         $destination_file Full destination file path.
	 */
	private function append_if_changed( Analyze_Result $result, string $relative_dir, string $name, string $source_file, string $destination_file ): void {
		$mtime = @filemtime( $source_file );
		if ( false === $mtime ) {
			return;
		}

		if ( file_exists( $destination_file ) ) {
			if ( $mtime === filemtime( $destination_file ) ) {
				return;
			}
			$status = 'M';
		} else {
			$status = 'N';
		}

		$file         = new Migrate_File();
		$file->name   = $name;
		$file->path   = $relative_dir;
		$file->MTime  = $mtime;
		$file->status = $status;
		$result->files[] = $file;
	}

	/**
	 * Validate a cursor's pending-directory queue against the source root.
	 *
	 * A malformed entry (path traversal, null byte, absolute path) is a tampered/corrupt cursor,
	 * not a real filesystem path, and is dropped silently. A well-formed entry that no longer
	 * resolves inside the root is most likely a directory removed or renamed between batches —
	 * that one is recorded in $result->unreadable_dirs instead, same as a mid-scan scandir()
	 * failure, so it isn't mistaken for "confirmed to have no differences".
	 *
	 * @param array<string, mixed>|null $cursor      Cursor from the browser.
	 * @param string                    $source_root Canonical source root.
	 * @param Analyze_Result             $result     Result to record vanished directories into.
	 * @return array<int, string>
	 */
	private function sanitize_pending_dirs( ?array $cursor, string $source_root, Analyze_Result $result ): array {
		if ( null === $cursor || ! isset( $cursor['pending_dirs'] ) || ! is_array( $cursor['pending_dirs'] ) ) {
			return array( '' );
		}

		$pending_dirs = array();
		foreach ( $cursor['pending_dirs'] as $raw ) {
			$relative = $this->normalize_relative_dir( is_string( $raw ) ? $raw : '' );
			if ( false === $relative ) {
				continue;
			}

			if ( '' === $relative ) {
				$pending_dirs[] = $relative;
				continue;
			}

			$full = $source_root . '/' . $relative;
			if ( is_dir( $full ) && $this->is_within_root( $full, $source_root ) ) {
				$pending_dirs[] = $relative;
				continue;
			}

			$result->unreadable_dirs[] = $relative;
		}

		return $pending_dirs;
	}

	/**
	 * Copy a batch of files for up to ~5 seconds.
	 *
	 * The file descriptors come from the browser, so every path is re-validated and
	 * the exclusion rules are re-applied here; never trust the submitted batch.
	 *
	 * @param array<int, Migrate_File> $files            Files to copy.
	 * @param string                   $source_path      Source root.
	 * @param string                   $destination_path Target root.
	 * @param array<int, string>       $excluded_files   Filename patterns.
	 * @param array<int, string>       $excluded_folders Relative folder prefixes.
	 * @return Migrate_Result
	 */
	public function update_files( array $files, string $source_path, string $destination_path, array $excluded_files = array(), array $excluded_folders = array() ): Migrate_Result {
		$start_time    = time();
		$current_time  = $start_time;
		$error_counter = 0;
		$result        = new Migrate_Result();

		$source_root = $this->resolve_root( $source_path );
		if ( false === $source_root ) {
			$result->return_code     = -1;
			$result->return_message  = "Source directory not exists: $source_path";
			$result->remaining_files = $files;
			return $result;
		}

		$destination_root = $this->resolve_root( $destination_path );
		if ( false === $destination_root ) {
			$result->return_code     = -1;
			$result->return_message  = "Target directory not exists: $destination_path";
			$result->remaining_files = $files;
			return $result;
		}

		while ( isset( $files[0] ) && ( $current_time < $start_time + 5 ) ) {
			$file = $files[0];

			if ( $this->copy_single_file( $file, $source_root, $destination_root, $excluded_files, $excluded_folders ) ) {
				$result->success_files[] = $file;
			} else {
				$result->failed_files[] = $file;
				++$error_counter;
			}

			$files        = array_slice( $files, 1 );
			$current_time = time();
		}

		$result->remaining_files = $files;
		$result->return_code     = $error_counter;
		$result->return_message  = 0 === $result->return_code ? 'All done' : 'Failed to copy some files.';
		return $result;
	}

	/**
	 * Validate and copy a single file descriptor.
	 *
	 * On failure the error details are written into the descriptor itself.
	 *
	 * @param Migrate_File       $file             File descriptor from the request.
	 * @param string             $source_root      Canonical source root, no trailing separator.
	 * @param string             $destination_root Canonical target root, no trailing separator.
	 * @param array<int, string> $excluded_files   Filename patterns.
	 * @param array<int, string> $excluded_folders Relative folder prefixes.
	 * @return bool
	 */
	private function copy_single_file(
		Migrate_File $file,
		string $source_root,
		string $destination_root,
		array $excluded_files,
		array $excluded_folders
	): bool {
		$relative_dir = $this->normalize_relative_dir( $file->path );

		if ( false === $relative_dir || ! $this->is_plain_file_name( $file->name ) ) {
			$file->error   = 'Rejected: unsafe file path';
			$file->message = array(
				'path' => $file->path,
				'name' => $file->name,
			);
			return false;
		}

		if (
			$this->is_directory_excluded( $relative_dir, $excluded_folders )
			|| $this->is_file_excluded( $file->name, $excluded_files )
		) {
			$file->error   = 'Rejected: excluded by configuration';
			$file->message = array(
				'path' => $relative_dir,
				'name' => $file->name,
			);
			return false;
		}

		$relative_file    = '' === $relative_dir ? $file->name : $relative_dir . '/' . $file->name;
		$source_file      = "$source_root/$relative_file";
		$destination_dir  = '' === $relative_dir ? $destination_root : "$destination_root/$relative_dir";
		$destination_file = "$destination_root/$relative_file";

		// Resolves symlinks too, so a link inside the source cannot point outside of it.
		if ( ! is_file( $source_file ) || ! $this->is_within_root( $source_file, $source_root ) ) {
			$file->error   = "Rejected: '$relative_file' is not a regular file inside the source root";
			$file->message = array();
			return false;
		}

		$folder_result = $this->create_folder_if_not_exists( $destination_dir );
		if ( true !== $folder_result ) {
			$file->error   = "Failed to create directory: '$destination_dir'";
			$file->message = $folder_result;
			return false;
		}

		if ( ! $this->is_within_root( $destination_dir, $destination_root ) ) {
			$file->error   = "Rejected: destination directory of '$relative_file' escapes the target root";
			$file->message = array();
			return false;
		}

		if ( file_exists( $destination_file ) && ! $this->is_within_root( $destination_file, $destination_root ) ) {
			$file->error   = "Rejected: destination of '$relative_file' escapes the target root";
			$file->message = array();
			return false;
		}

		$copy_result = $this->copy_and_keep_modification_time( $source_file, $destination_file );
		if ( true !== $copy_result ) {
			$file->error   = "Failed to copy '$source_file' to '$destination_file': ";
			$file->message = $copy_result;
			return false;
		}

		return true;
	}

	/**
	 * Canonicalize a root directory.
	 *
	 * @param string $path Directory path.
	 * @return string|false Canonical path without trailing separator.
	 */
	private function resolve_root( string $path ) {
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$real = realpath( $path );
		if ( false === $real || ! is_dir( $real ) ) {
			return false;
		}

		return rtrim( $real, '/\\' );
	}

	/**
	 * Whether a name is a bare file name, without any directory component.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private function is_plain_file_name( string $name ): bool {
		if ( '' === $name || '.' === $name || '..' === $name ) {
			return false;
		}
		if ( false !== strpos( $name, "\0" ) ) {
			return false;
		}
		return false === strpbrk( $name, '/\\' );
	}

	/**
	 * Normalize a client supplied relative directory to "a/b" form.
	 *
	 * @param string $path Relative directory path.
	 * @return string|false Normalized path ('' for the root), or false when unsafe.
	 */
	private function normalize_relative_dir( string $path ) {
		if ( false !== strpos( $path, "\0" ) ) {
			return false;
		}

		// Absolute paths and Windows drive prefixes are never relative to a root.
		if ( '' !== $path && ( '/' === $path[0] || '\\' === $path[0] ) ) {
			return false;
		}
		if ( preg_match( '#^[A-Za-z]:#', $path ) ) {
			return false;
		}

		$segments = array();
		foreach ( preg_split( '#[/\\\\]+#', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return false;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Whether a real path stays inside a canonical root.
	 *
	 * @param string $path Existing path to check.
	 * @param string $root Canonical root without trailing separator.
	 * @return bool
	 */
	private function is_within_root( string $path, string $root ): bool {
		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}
		$real = rtrim( $real, '/\\' );

		if ( $real === $root ) {
			return true;
		}

		$prefix = $root . DIRECTORY_SEPARATOR;

		// Windows paths are case insensitive.
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return 0 === strncasecmp( $real, $prefix, strlen( $prefix ) );
		}

		return 0 === strncmp( $real, $prefix, strlen( $prefix ) );
	}

	/**
	 * @param string             $filename       Filename.
	 * @param array<int, string> $excluded_files Patterns.
	 * @return bool
	 */
	private function is_file_excluded( string $filename, array $excluded_files ): bool {
		foreach ( $excluded_files as $pattern ) {
			if ( fnmatch( $pattern, $filename ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string             $folder            Relative folder path.
	 * @param array<int, string> $excluded_folders  Prefixes ending with /.
	 * @return bool
	 */
	private function is_directory_excluded( string $folder, array $excluded_folders ): bool {
		$folder = rtrim( $folder, "/\\" ) . '/';
		foreach ( $excluded_folders as $exclude ) {
			$part = substr( $folder, 0, strlen( $exclude ) );
			if ( $part === $exclude ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $folder Directory path.
	 * @return true|array<string, mixed>
	 */
	private function create_folder_if_not_exists( string $folder ) {
		if ( file_exists( $folder ) ) {
			return true;
		}

		if ( true === mkdir( $folder, 0755, true ) ) {
			return true;
		}

		$last = error_get_last();
		return is_array( $last ) ? $last : array( 'message' => 'mkdir failed' );
	}

	/**
	 * Copy file and preserve mtime. Successful copy without readable mtime still counts as success.
	 *
	 * @param string $source      Source file.
	 * @param string $destination Destination file.
	 * @return true|array<string, mixed>
	 */
	private function copy_and_keep_modification_time( string $source, string $destination ) {
		if ( ! copy( $source, $destination ) ) {
			$last = error_get_last();
			return is_array( $last ) ? $last : array( 'message' => 'copy failed' );
		}

		$source_mtime = @filemtime( $source );
		if ( false !== $source_mtime ) {
			@touch( $destination, $source_mtime );
		}

		return true;
	}
}
