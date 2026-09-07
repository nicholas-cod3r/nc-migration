<?php
/**
 * Migration database dump, import, and URL replacement.
 *
 * @package NC\Migration
 */

declare(strict_types=1);

namespace NC\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles DB transfer to a target instance.
 */
class Migration_Database {

	/** Fallback DB charset when the site does not define/expose one. */
	private const DEFAULT_CHARSET = 'utf8mb4';

	/** @var mysqli|null */
	private $url_replace_db = null;

	/** @var DB_Result|null */
	private $url_replace_result = null;

	/** @var string|null */
	private $source_url = null;

	/** @var string|null */
	private $destination_url = null;

	/**
	 * Dump source DB, import into target, replace URLs.
	 *
	 * @param object $target Target config node (Path, URL).
	 * @return DB_Result
	 */
	public function transfer( object $target ): DB_Result {
		$result      = new DB_Result();
		$export_file = ABSPATH . 'export.sql.gz';

		try {
			$destination_config_file = rtrim( (string) $target->Path, "/\\" ) . '/wp-config.php';
			$destination_db_params   = $this->get_wp_config_db_params( $destination_config_file );

			if ( false === $destination_db_params ) {
				$result->return_code    = -2;
				$result->return_message = "Failed to load the config file on destination: $destination_config_file";
				return $result;
			}

			if ( ! $this->check_db_params_exists( $destination_db_params ) ) {
				$result->return_code    = -3;
				$result->return_message = 'Not enough parameter in the destination config file';
				return $result;
			}

			$destination_charset = $this->resolve_charset( $destination_db_params['db_charset'] ?? null );

			$this->dump_db( $export_file, $destination_db_params['table_prefix'] );
			$this->import_db( $export_file, $destination_db_params, $destination_charset );

			return $this->update_urls_in_db( $destination_db_params, (string) $target->URL, $destination_charset );
		} catch ( \Throwable $e ) {
			$result->return_code    = -1;
			$result->return_message = $e->getMessage();
			if ( file_exists( $export_file ) ) {
				@unlink( $export_file );
			}
			return $result;
		}
	}

	/**
	 * @param string $output_file               Dump path.
	 * @param string $destination_table_prefix  Target table prefix.
	 */
	private function dump_db( string $output_file, string $destination_table_prefix ): void {
		global $table_prefix;

		$source_charset = $this->resolve_charset( defined( 'DB_CHARSET' ) ? DB_CHARSET : null );

		$db_source = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		try {
			$dump                       = new \MySQLDump( $db_source, $source_charset );
			$dump->table_prefix         = $table_prefix;
			$dump->export_table_prefix  = $destination_table_prefix;
			$dump->save( $output_file );
		} finally {
			$db_source->close();
		}
	}

	/**
	 * @param string               $sql_file               Dump path.
	 * @param array<string, string> $destination_db_params Target credentials.
	 * @param string               $destination_charset   Target DB connection charset.
	 */
	private function import_db( string $sql_file, array $destination_db_params, string $destination_charset ): void {
		$db_destination = new \mysqli(
			$destination_db_params['db_host'],
			$destination_db_params['db_user'],
			$destination_db_params['db_password'],
			$destination_db_params['db_name']
		);
		try {
			$import = new \MySQLImport( $db_destination, $destination_charset );
			$import->load( $sql_file );
		} finally {
			$db_destination->close();
			if ( file_exists( $sql_file ) ) {
				unlink( $sql_file );
			}
		}
	}

	/**
	 * @param array<string, string> $destination_db_params Params.
	 * @return bool
	 */
	private function check_db_params_exists( array $destination_db_params ): bool {
		return isset(
			$destination_db_params['db_host'],
			$destination_db_params['db_user'],
			$destination_db_params['db_password'],
			$destination_db_params['db_name'],
			$destination_db_params['table_prefix']
		);
	}

	/**
	 * @param string $file Path to wp-config.php.
	 * @return array<string, string>|false
	 */
	private function get_wp_config_db_params( string $file ) {
		$content = @file_get_contents( $file );
		if ( false === $content ) {
			return false;
		}

		$params = array(
			'db_name'      => "/define.+?'DB_NAME'.+?'(.*?)'.+/",
			'db_user'      => "/define.+?'DB_USER'.+?'(.*?)'.+/",
			'db_password'  => "/define.+?'DB_PASSWORD'.+?'(.*?)'.+/",
			'db_host'      => "/define.+?'DB_HOST'.+?'(.*?)'.+/",
			'db_charset'   => "/define.+?'DB_CHARSET'.+?'(.*?)'.+/",
			'table_prefix' => "/\\\$table_prefix.+?'(.+?)'.+/",
		);

		$result = array();
		foreach ( $params as $key => $pattern ) {
			if ( preg_match_all( $pattern, $content, $matches ) ) {
				$result[ $key ] = $matches[1][0];
			}
		}

		return $result;
	}

	/**
	 * Resolve a MySQL connection charset, falling back to a sane default when unset/empty.
	 *
	 * @param string|null $charset Candidate charset value (may be null/empty).
	 * @return string
	 */
	private function resolve_charset( ?string $charset ): string {
		$charset = null === $charset ? '' : trim( $charset );
		return '' !== $charset ? $charset : self::DEFAULT_CHARSET;
	}

	/**
	 * @param mysqli    $db              Destination DB connection.
	 * @param DB_Result $result          Result accumulator.
	 * @param string    $source_url      Source site URL.
	 * @param string    $destination_url Target site URL.
	 */
	private function begin_url_replace_run( \mysqli $db, DB_Result $result, string $source_url, string $destination_url ): void {
		if ( null !== $this->url_replace_db ) {
			throw new \LogicException( 'URL replace run already active.' );
		}

		$this->url_replace_db       = $db;
		$this->url_replace_result   = $result;
		$this->source_url           = $source_url;
		$this->destination_url      = $destination_url;
	}

	/**
	 * Clear URL replace run context.
	 */
	private function end_url_replace_run(): void {
		$this->url_replace_db     = null;
		$this->url_replace_result = null;
		$this->source_url         = null;
		$this->destination_url    = null;
	}

	/**
	 * Replace source site URL with destination URL in the target DB.
	 *
	 * @param array<string, string> $destination_db_params Target credentials.
	 * @param string                $destination_url       Target home URL.
	 * @param string                $destination_charset   Target DB connection charset.
	 * @return DB_Result
	 */
	private function update_urls_in_db( array $destination_db_params, string $destination_url, string $destination_charset ): DB_Result {
		$result     = new DB_Result();
		$source_url = get_bloginfo( 'wpurl' );

		$db = new \mysqli(
			$destination_db_params['db_host'],
			$destination_db_params['db_user'],
			$destination_db_params['db_password'],
			$destination_db_params['db_name']
		);

		try {
			if ( 0 !== $db->connect_errno ) {
				$result->return_code    = -1;
				$result->return_message = 'Failed to destination database: ' . $db->connect_error;
				return $result;
			}

			$db->set_charset( $destination_charset );

			$this->begin_url_replace_run( $db, $result, $source_url, $destination_url );

			$prefix            = $destination_db_params['table_prefix'];
			$options_table     = $prefix . 'options';
			$posts_table       = $prefix . 'posts';
			$postmeta_table    = $prefix . 'postmeta';
			$users_table       = $prefix . 'users';
			$usermeta_table    = $prefix . 'usermeta';
			$termmeta_table    = $prefix . 'termmeta';
			$comments_table    = $prefix . 'comments';
			$commentmeta_table = $prefix . 'commentmeta';

			$options_ok = $this->replace_column_preserving_serialization(
				$options_table,
				'option_id',
				'option_name',
				'option_value',
				-2,
				-3,
				'Serialized options',
				'Normal options',
				'option',
				'Failed to get options from destination database',
				'Failed to update options'
			);
			if ( ! $options_ok ) {
				return $result;
			}

			$posts_ok = $this->replace_column_preserving_serialization(
				$posts_table,
				'ID',
				'ID',
				'post_content',
				-6,
				-6,
				'Serialized posts content',
				'Normal posts content',
				'post',
				'Failed to get posts from destination database',
				'Failed to update posts content'
			);
			if ( ! $posts_ok ) {
				return $result;
			}

			$guid_ok = $this->replace_in_column(
				$posts_table,
				'guid',
				'',
				'Posts GUIDs updated',
				-7
			);
			if ( ! $guid_ok ) {
				return $result;
			}

			$postmeta_ok = $this->replace_column_preserving_serialization(
				$postmeta_table,
				'meta_id',
				'meta_key',
				'meta_value',
				-10,
				-11,
				'Serialized postmeta',
				'Normal postmeta',
				'postmeta',
				'Failed to get postmeta from destination database',
				'Failed to update postmeta'
			);
			if ( ! $postmeta_ok ) {
				return $result;
			}

			$users_ok = $this->replace_in_column(
				$users_table,
				'user_url',
				'',
				'Users updated',
				-9
			);
			if ( ! $users_ok ) {
				return $result;
			}

			$usermeta_ok = $this->replace_column_preserving_serialization(
				$usermeta_table,
				'umeta_id',
				'meta_key',
				'meta_value',
				-13,
				-14,
				'Serialized usermeta',
				'Normal usermeta',
				'usermeta',
				'Failed to get usermeta from destination database',
				'Failed to update usermeta'
			);
			if ( ! $usermeta_ok ) {
				return $result;
			}

			$termmeta_ok = $this->replace_column_preserving_serialization(
				$termmeta_table,
				'meta_id',
				'meta_key',
				'meta_value',
				-16,
				-17,
				'Serialized termmeta',
				'Normal termmeta',
				'termmeta',
				'Failed to get termmeta from destination database',
				'Failed to update termmeta'
			);
			if ( ! $termmeta_ok ) {
				return $result;
			}

			$commentmeta_ok = $this->replace_column_preserving_serialization(
				$commentmeta_table,
				'meta_id',
				'meta_key',
				'meta_value',
				-19,
				-20,
				'Serialized commentmeta',
				'Normal commentmeta',
				'commentmeta',
				'Failed to get commentmeta from destination database',
				'Failed to update commentmeta'
			);
			if ( ! $commentmeta_ok ) {
				return $result;
			}

			$comment_content_ok = $this->replace_column_preserving_serialization(
				$comments_table,
				'comment_ID',
				'comment_ID',
				'comment_content',
				-22,
				-22,
				'Serialized comments content',
				'Normal comments content',
				'comment',
				'Failed to get comments from destination database',
				'Failed to update comments content'
			);
			if ( ! $comment_content_ok ) {
				return $result;
			}

			$comment_author_url_ok = $this->replace_in_column(
				$comments_table,
				'comment_author_url',
				'',
				'Comment author URLs updated',
				-23
			);
			if ( ! $comment_author_url_ok ) {
				return $result;
			}

			$this->replace_in_uncovered_tables(
				$destination_db_params['db_name'],
				$prefix,
				array(
					$options_table,
					$posts_table,
					$postmeta_table,
					$users_table,
					$usermeta_table,
					$termmeta_table,
					$comments_table,
					$commentmeta_table,
				)
			);

			$result->return_code    = 0;
			$result->return_message = '';
			return $result;
		} finally {
			$this->end_url_replace_run();
			$db->close();
		}
	}

	/**
	 * Escape source URL for safe use in LIKE comparison.
	 * Applies both LIKE wildcards and SQL string escaping.
	 *
	 * @return string Escaped URL ready for "LIKE '%..%'" clause.
	 */
	private function source_url_like_sql(): string {
		$db = $this->url_replace_db;
		return $db->real_escape_string( $this->escape_sql_like_parameter( $this->source_url ) );
	}

	/**
	 * Replace URLs in a column that may hold PHP-serialized values (options, postmeta, etc.).
	 *
	 * Candidate rows are pre-filtered in SQL by a plain substring LIKE (cheap, no false
	 * negatives). Each candidate is then classified in PHP with WordPress's own
	 * `is_serialized()` rather than a `LIKE 'a:%'`-style prefix guess, which misclassifies plain
	 * text that happens to start with a serialization type letter (e.g. Hungarian text like
	 * "a: valami http://...") and misses real serialized values outside its hardcoded prefix
	 * list. Serialized rows are unserialized, patched, and re-serialized; everything else gets a
	 * direct string replace. Both cases are written back with the same per-row UPDATE.
	 *
	 * @param string $table                 Table name.
	 * @param string $id_column             Primary key column.
	 * @param string $name_column           Label column for log/error messages.
	 * @param string $value_column          Value column (serialized or plain).
	 * @param int    $select_error_code     Return code when SELECT fails.
	 * @param int    $update_error_code     Return code when not all rows update.
	 * @param string $serialized_log_label  Success log prefix for rows that were serialized.
	 * @param string $plain_log_label       Success log prefix for rows that were plain text.
	 * @param string $entity_singular       Entity name for row-level errors.
	 * @param string $fetch_fail_message    Message when SELECT fails.
	 * @param string $update_fail_message   Message when batch update incomplete.
	 * @return bool
	 */
	private function replace_column_preserving_serialization(
		string $table,
		string $id_column,
		string $name_column,
		string $value_column,
		int $select_error_code,
		int $update_error_code,
		string $serialized_log_label,
		string $plain_log_label,
		string $entity_singular,
		string $fetch_fail_message,
		string $update_fail_message
	): bool {
		$db     = $this->url_replace_db;
		$result = $this->url_replace_result;

		$source_url_sql = $this->source_url_like_sql();

		$sql = "SELECT `$id_column`, `$name_column`, `$value_column` FROM `$table`
			WHERE `$value_column` LIKE '%$source_url_sql%'";

		$candidate_values = $db->query( $sql );
		if ( false === $candidate_values ) {
			$result->return_code    = $select_error_code;
			$result->return_message = $fetch_fail_message . ': ' . $db->error;
			return false;
		}

		$found = (int) $candidate_values->num_rows;

		if ( 0 === $found ) {
			$result->messages[] = "$serialized_log_label updated: 0";
			$result->messages[] = "$plain_log_label updated: 0";
			$candidate_values->free();
			return true;
		}

		$replaced_rows = array();
		while ( $row = $candidate_values->fetch_assoc() ) {
			$raw_value     = $row[ $value_column ];
			$was_serialized = $this->is_serialized_value( $raw_value );

			if ( $was_serialized ) {
				$decoded = @unserialize( $raw_value );
				try {
					$new_value = $this->replace_in_unserialized_value( $decoded );
				} catch ( \Exception $e ) {
					$result->errors[] = "Skipped $entity_singular '{$row[ $name_column ]}': " . $e->getMessage();
					continue;
				}
				if ( null === $new_value ) {
					$result->errors[] = "Failed to unserialize $entity_singular '{$row[ $name_column ]}'";
					continue;
				}
			} else {
				$new_value = str_replace( $this->source_url, $this->destination_url, $raw_value );
			}

			$replaced_rows[] = array(
				'id'         => $row[ $id_column ],
				'name'       => $row[ $name_column ],
				'value'      => $new_value,
				'serialized' => $was_serialized,
			);
		}
		$candidate_values->free();

		$success_serialized = 0;
		$success_plain       = 0;

		foreach ( $replaced_rows as $row ) {
			$id            = $db->real_escape_string( (string) $row['id'] );
			$name          = (string) $row['name'];
			$value         = $db->real_escape_string( (string) $row['value'] );
			$update_sql    = "UPDATE `$table` SET `$value_column`='$value' WHERE `$id_column`='$id'";
			$update_result = $db->query( $update_sql );

			if ( true !== $update_result ) {
				$result->errors[] = "Failed to update $entity_singular '$name': " . $db->error;
				continue;
			}
			if ( $db->affected_rows > 0 ) {
				if ( $row['serialized'] ) {
					++$success_serialized;
				} else {
					++$success_plain;
				}
			} else {
				$result->errors[] = "No rows affected while updating $entity_singular '$name'";
			}
		}

		$result->messages[] = "$serialized_log_label updated: $success_serialized";
		$result->messages[] = "$plain_log_label updated: $success_plain";

		$success = $success_serialized + $success_plain;
		if ( $success !== $found ) {
			$result->return_code    = $update_error_code;
			$result->return_message = $update_fail_message;
			return false;
		}

		return true;
	}

	/**
	 * Replace URLs in plain (non-serialized) column values via SQL REPLACE.
	 *
	 * @param string $table               Table name.
	 * @param string $value_column        Value column.
	 * @param int    $error_code          Return code when UPDATE fails (ignored when $fatal is false).
	 * @param string $log_label           Success log prefix.
	 * @param string $update_fail_message Message when UPDATE fails.
	 * @param bool   $fatal               When false, a failure is logged to $result->errors and
	 *                                    execution continues instead of aborting the whole run.
	 * @return bool
	 */
	private function replace_plain_column(
		string $table,
		string $value_column,
		int $error_code,
		string $log_label,
		string $update_fail_message,
		bool $fatal = true
	): bool {
		$db     = $this->url_replace_db;
		$result = $this->url_replace_result;

		$source_sql = $db->real_escape_string( $this->source_url );
		$dest_sql   = $db->real_escape_string( $this->destination_url );
		$source_url_like = $this->source_url_like_sql();

		$sql = "UPDATE `$table` SET `$value_column` = REPLACE(`$value_column`, '$source_sql', '$dest_sql')
			WHERE `$value_column` LIKE '%$source_url_like%'
			AND " . $this->sql_is_plain_value( $value_column );

		$update_result = $db->query( $sql );
		if ( true !== $update_result ) {
			if ( $fatal ) {
				$result->return_code    = $error_code;
				$result->return_message = $update_fail_message . ': ' . $db->error;
				return false;
			}
			$result->errors[] = $update_fail_message . ' (' . $table . '.' . $value_column . '): ' . $db->error;
			return true;
		}

		$result->messages[] = "$log_label updated: " . (int) $db->affected_rows;
		return true;
	}

	/**
	 * List destination tables sharing the given prefix.
	 *
	 * @param \mysqli $db     Destination DB connection.
	 * @param string  $db_name Destination database name.
	 * @param string  $prefix Table prefix.
	 * @return array<int, string>
	 */
	private function get_tables_with_prefix( \mysqli $db, string $db_name, string $prefix ): array {
		$db_name_sql = $db->real_escape_string( $db_name );
		$prefix_like = $db->real_escape_string( $this->escape_sql_like_parameter( $prefix ) );

		$sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_SCHEMA = '$db_name_sql' AND TABLE_NAME LIKE '{$prefix_like}%'";

		$rows   = $db->query( $sql );
		$tables = array();
		if ( false === $rows ) {
			return $tables;
		}
		while ( $row = $rows->fetch_row() ) {
			$tables[] = $row[0];
		}
		$rows->free();
		return $tables;
	}

	/**
	 * List text-like column names for a table.
	 *
	 * @param \mysqli $db      Destination DB connection.
	 * @param string  $db_name Destination database name.
	 * @param string  $table   Table name.
	 * @return array<int, string>
	 */
	private function get_text_columns( \mysqli $db, string $db_name, string $table ): array {
		$db_name_sql = $db->real_escape_string( $db_name );
		$table_sql   = $db->real_escape_string( $table );

		$sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = '$db_name_sql' AND TABLE_NAME = '$table_sql'
			AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')";

		$rows    = $db->query( $sql );
		$columns = array();
		if ( false === $rows ) {
			return $columns;
		}
		while ( $row = $rows->fetch_row() ) {
			$columns[] = $row[0];
		}
		$rows->free();
		return $columns;
	}

	/**
	 * Best-effort URL replace across every text-like column of every destination table sharing
	 * the target prefix, except tables already handled explicitly elsewhere. Covers current and
	 * future plugin/theme tables (e.g. custom post-type-adjacent or standalone tables) without
	 * needing to know their schema in advance. Failures are logged but never abort the migration.
	 *
	 * @param string               $db_name         Destination database name.
	 * @param string               $prefix          Table prefix.
	 * @param array<int, string>   $excluded_tables Tables already handled explicitly.
	 */
	private function replace_in_uncovered_tables( string $db_name, string $prefix, array $excluded_tables ): void {
		$db     = $this->url_replace_db;
		$result = $this->url_replace_result;

		$tables         = $this->get_tables_with_prefix( $db, $db_name, $prefix );
		$scanned_tables = 0;
		$updated_columns = 0;

		foreach ( $tables as $table ) {
			if ( in_array( $table, $excluded_tables, true ) ) {
				continue;
			}

			$columns = $this->get_text_columns( $db, $db_name, $table );
			if ( empty( $columns ) ) {
				continue;
			}

			++$scanned_tables;
			$table_updated = 0;

			foreach ( $columns as $column ) {
				$before_message_count = count( $result->messages );

				$ok = $this->replace_plain_column(
					$table,
					$column,
					0,
					"$table.$column",
					"Failed to update $table.$column",
					false
				);

				if ( $ok && count( $result->messages ) > $before_message_count ) {
					// replace_plain_column() logged a message; only keep it if rows were affected.
					$last_message = array_pop( $result->messages );
					if ( ! preg_match( '/updated: 0$/', $last_message ) ) {
						++$table_updated;
						++$updated_columns;
					}
				}
			}

			if ( $table_updated > 0 ) {
				$result->messages[] = "$table: $table_updated column(s) updated";
			}
		}

		if ( $scanned_tables > 0 ) {
			$result->messages[] = "Other tables scanned: $scanned_tables, columns updated: $updated_columns";
		}
	}

	/**
	 * @param string $table           Table name.
	 * @param string $column          Column name.
	 * @param string $extra_condition Extra AND condition (may be empty).
	 * @param string $success_label   Message label.
	 * @param int    $error_code      Return code on failure.
	 * @return bool
	 */
	private function replace_in_column(
		string $table,
		string $column,
		string $extra_condition,
		string $success_label,
		int $error_code
	): bool {
		$db     = $this->url_replace_db;
		$result = $this->url_replace_result;

		$source_sql = $db->real_escape_string( $this->source_url );
		$dest_sql   = $db->real_escape_string( $this->destination_url );
		$source_url_like = $this->source_url_like_sql();
		$extra      = '' === $extra_condition ? '' : ' AND ' . $extra_condition;
		$sql        = "UPDATE `$table` SET `$column` = REPLACE(`$column`, '$source_sql', '$dest_sql')
			WHERE `$column` LIKE '%$source_url_like%'$extra";

		$update_result = $db->query( $sql );
		if ( true !== $update_result ) {
			$result->return_code    = $error_code;
			$result->return_message = "Failed to update $column: " . $db->error;
			return false;
		}

		$result->messages[] = $success_label . ': ' . (int) $db->affected_rows;
		return true;
	}

	/**
	 * SQL fragment: column value is not PHP-serialized (safe for plain REPLACE).
	 *
	 * Only used by {@see replace_plain_column()} for uncovered/unknown tables discovered by
	 * {@see replace_in_uncovered_tables()}, where there's no reliable primary key to fetch rows
	 * by and classify with real {@see is_serialized_value()} in PHP. Known tables/columns
	 * (options, postmeta, usermeta, termmeta, commentmeta, post/comment content) go through
	 * {@see replace_column_preserving_serialization()} instead, which doesn't have this
	 * heuristic's false-positive/false-negative risk.
	 *
	 * @param string $column Column name.
	 * @return string
	 */
	private function sql_is_plain_value( string $column ): string {
		return "$column NOT LIKE 'a:%' AND $column NOT LIKE 'O:%' AND $column NOT LIKE 's:%'";
	}

	/**
	 * Whether a raw column value is PHP-serialized data.
	 *
	 * Delegates to WordPress core's `is_serialized()`, which structurally validates the value
	 * (type marker, length/byte counts, closing delimiter) instead of guessing from a handful of
	 * leading characters, so it doesn't misfire on plain text that happens to start with a
	 * serialization type letter (e.g. "a:", "s:", "O:").
	 *
	 * @param string $value Raw column value.
	 * @return bool
	 */
	private function is_serialized_value( string $value ): bool {
		return \is_serialized( $value );
	}

	/**
	 * Replace URLs inside an unserialized value and return re-serialized bytes.
	 *
	 * @param mixed $decoded Value from unserialize().
	 * @return string|null Serialized value, or null when input is not processable.
	 */
	private function replace_in_unserialized_value( $decoded ): ?string {
		if ( is_string( $decoded ) ) {
			if ( false !== strpos( $decoded, $this->source_url ) ) {
				$decoded = str_replace( $this->source_url, $this->destination_url, $decoded );
			}
			return serialize( $decoded );
		}

		if ( is_array( $decoded ) || is_object( $decoded ) ) {
			$this->replace_string_in_structure( $decoded );
			return serialize( $decoded );
		}

		return null;
	}

	/**
	 * @param string $like Raw LIKE value.
	 * @return string
	 */
	private function escape_sql_like_parameter( string $like ): string {
		return str_replace( array( '_', '%' ), array( '\\_', '\\%' ), $like );
	}

	/**
	 * Recursively replace URL substrings in arrays/objects.
	 *
	 * Handles array keys that contain URLs (by rebuilding the array), and detects
	 * __PHP_Incomplete_Class objects which must not be re-serialized to avoid corruption.
	 *
	 * @param array<mixed>|object $structure Structure by reference.
	 * @param int                 $depth     Recursion depth.
	 * @throws Exception When recursion limit exceeded or incomplete class encountered.
	 */
	private function replace_string_in_structure( &$structure, int $depth = 0 ): void {
		if ( $depth >= 100 ) {
			throw new \Exception( 'replace_string_in_structure: possible infinite recursion' );
		}

		// Reject __PHP_Incomplete_Class early to prevent corruption on re-serialize.
		if ( $structure instanceof \__PHP_Incomplete_Class ) {
			throw new \Exception(
				'replace_string_in_structure: encountered __PHP_Incomplete_Class ' .
				'(class not loaded) - refusing to re-serialize to avoid data corruption'
			);
		}

		if ( is_array( $structure ) ) {
			// Rebuild array to allow key rewriting (foreach with by-ref only modifies values).
			$rebuilt = array();
			foreach ( $structure as $key => $value ) {
				// Replace URL in string keys.
				if ( is_string( $key ) && false !== strpos( $key, $this->source_url ) ) {
					$key = str_replace( $this->source_url, $this->destination_url, $key );
				}
				// Recurse into values.
				if ( is_string( $value ) ) {
					if ( false !== strpos( $value, $this->source_url ) ) {
						$value = str_replace( $this->source_url, $this->destination_url, $value );
					}
				} elseif ( is_array( $value ) || is_object( $value ) ) {
					$this->replace_string_in_structure( $value, $depth + 1 );
				}
				$rebuilt[ $key ] = $value;
			}
			$structure = $rebuilt;
			return;
		}

		// is_object( $structure )
		foreach ( $structure as $key => $value ) {
			if ( is_string( $value ) ) {
				if ( false !== strpos( $value, $this->source_url ) ) {
					$structure->$key = str_replace( $this->source_url, $this->destination_url, $value );
				}
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$this->replace_string_in_structure( $value, $depth + 1 );
				$structure->$key = $value;
			}
		}
	}
}
