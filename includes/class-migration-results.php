<?php
/**
 * Migration result DTOs.
 *
 * @package NC\Migration
 */

declare(strict_types=1);

namespace NC\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File copy batch result.
 */
class Migrate_Result {
	/**
	 * @var int
	 */
	public $return_code = -255;

	/**
	 * @var string
	 */
	public $return_message = '';

	/**
	 * @var array<int, Migrate_File>
	 */
	public $success_files = array();

	/**
	 * @var array<int, Migrate_File>
	 */
	public $failed_files = array();

	/**
	 * @var array<int, Migrate_File>
	 */
	public $remaining_files = array();
}

/**
 * Single file descriptor for analyze/copy.
 */
class Migrate_File {
	/**
	 * @var string
	 */
	public $name = '';

	/**
	 * @var string
	 */
	public $path = '';

	/**
	 * @var int
	 */
	public $MTime = 0;

	/**
	 * @var string
	 */
	public $status = '';

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var mixed
	 */
	public $message = '';
}

/**
 * File analyze result. Represents a single batch of a (possibly multi-request) scan;
 * see Migration_File_Sync::scan_batch().
 */
class Analyze_Result {
	/**
	 * @var int
	 */
	public $return_code = -255;

	/**
	 * @var string
	 */
	public $return_message = '';

	/**
	 * Differences found in this batch only, not the whole tree.
	 *
	 * @var array<int, Migrate_File>
	 */
	public $files = array();

	/**
	 * Relative paths of directories that could not be read in this batch (e.g. removed or
	 * renamed mid-scan) — their contents were skipped, not confirmed to have no differences.
	 *
	 * @var array<int, string>
	 */
	public $unreadable_dirs = array();

	/**
	 * Opaque state to pass back into the next scan_batch() call. Null once done.
	 *
	 * @var array<string, mixed>|null
	 */
	public $cursor = null;

	/**
	 * Whether the whole source tree has been scanned.
	 *
	 * @var bool
	 */
	public $done = false;
}

/**
 * Database transfer / URL replace result.
 */
class DB_Result {
	/**
	 * @var int
	 */
	public $return_code = -255;

	/**
	 * @var string
	 */
	public $return_message = '';

	/**
	 * @var array<int, string>
	 */
	public $errors = array();

	/**
	 * @var array<int, string>
	 */
	public $messages = array();
}
