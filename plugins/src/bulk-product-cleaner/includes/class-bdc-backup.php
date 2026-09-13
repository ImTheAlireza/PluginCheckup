<?php
/**
 * Backup & restore engine.
 *
 * Every deletion run creates a self-contained backup set:
 *   uploads/bdc-backups/{run_id}/
 *       manifest.json      Run metadata + retention info.
 *       products.jsonl     One JSON object per product (post, meta, terms, comments, variations).
 *       files/             Copies of every physical image file removed.
 *
 * Backups are retained for BDC_RETENTION_DAYS (90) days and purged by a daily cron.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BDC_Backup
 */
class BDC_Backup {

	const INDEX_OPTION = 'bdc_backup_index';
	const MANIFEST     = 'manifest.json';
	const RECORDS      = 'products.jsonl';

	/**
	 * Active run id, or empty when no run is open.
	 *
	 * @var string
	 */
	private $run_id = '';

	/**
	 * Absolute path to the active run directory.
	 *
	 * @var string
	 */
	private $run_dir = '';

	/**
	 * Open write handle for the records file.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Accumulated run statistics.
	 *
	 * @var array
	 */
	private $stats = array();

	/**
	 * Retention window in days.
	 *
	 * @return int
	 */
	public static function retention_days() {
		/**
		 * Filters how many days backups are kept before automatic purge.
		 *
		 * @param int $days Retention in days.
		 */
		$days = (int) apply_filters( 'bdc_backup_retention_days', BDC_RETENTION_DAYS );

		return max( 1, $days );
	}

	/**
	 * Validates a run id (defence against path traversal).
	 *
	 * @param string $run_id Candidate id.
	 * @return bool
	 */
	public static function is_valid_run_id( $run_id ) {
		return (bool) preg_match( '/^bdc-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', (string) $run_id );
	}

	/**
	 * Absolute directory for a run id, or empty string when invalid.
	 *
	 * @param string $run_id Run id.
	 * @return string
	 */
	public static function run_dir( $run_id ) {
		if ( ! self::is_valid_run_id( $run_id ) ) {
			return '';
		}

		return BDC_Install::backup_root() . '/' . $run_id;
	}

	/**
	 * Opens a new backup run.
	 *
	 * @param array $context Arbitrary run context (mode, filters, user).
	 * @return string|WP_Error Run id on success.
	 */
	public function start( array $context = array() ) {
		if ( ! BDC_Install::ensure_directories() ) {
			return new WP_Error(
				'bdc_backup_dir',
				__( 'پوشه پشتیبان قابل نوشتن نیست. عملیات حذف متوقف شد.', 'bulk-product-cleaner' )
			);
		}

		$run_id = 'bdc-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
		$dir    = BDC_Install::backup_root() . '/' . $run_id;

		if ( ! wp_mkdir_p( $dir ) || ! wp_mkdir_p( $dir . '/files' ) ) {
			return new WP_Error(
				'bdc_backup_mkdir',
				__( 'ساخت پوشه پشتیبان ناموفق بود.', 'bulk-product-cleaner' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $dir . '/' . self::RECORDS, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'bdc_backup_open',
				__( 'باز کردن فایل پشتیبان ناموفق بود.', 'bulk-product-cleaner' )
			);
		}

		$this->run_id  = $run_id;
		$this->run_dir = $dir;
		$this->handle  = $handle;
		$this->stats   = array(
			'products'    => 0,
			'variations'  => 0,
			'attachments' => 0,
			'files'       => 0,
			'bytes'       => 0,
		);

		$now       = time();
		$retention = self::retention_days();

		$manifest = array(
			'run_id'          => $run_id,
			'plugin_version'  => BDC_VERSION,
			'schema'          => 2,
			'site_url'        => home_url(),
			'created_gmt'     => gmdate( 'Y-m-d H:i:s', $now ),
			'created_ts'      => $now,
			'expires_ts'      => $now + ( $retention * DAY_IN_SECONDS ),
			'retention_days'  => $retention,
			'user_id'         => get_current_user_id(),
			'user_login'      => self::current_user_login(),
			'mode'            => isset( $context['mode'] ) ? (string) $context['mode'] : 'delete',
			'delete_images'   => ! empty( $context['delete_images'] ),
			'filters'         => isset( $context['filters'] ) ? $context['filters'] : array(),
			'status'          => 'running',
			'restored'        => false,
			'restored_gmt'    => '',
			'stats'           => $this->stats,
		);

		$this->write_manifest( $manifest );
		$this->index_upsert( $manifest );

		return $run_id;
	}

	/**
	 * Current user login, safe when no user is set (cron / CLI).
	 *
	 * @return string
	 */
	private static function current_user_login() {
		$user = wp_get_current_user();
		if ( $user && ! empty( $user->user_login ) ) {
			return (string) $user->user_login;
		}
		return defined( 'WP_CLI' ) && WP_CLI ? 'wp-cli' : 'system';
	}

	/**
	 * Snapshots a product (and everything needed to rebuild it) into the open run.
	 *
	 * MUST be called before the product is deleted.
	 *
	 * @param int   $product_id     Product ID.
	 * @param int[] $attachment_ids Attachment IDs that will be removed.
	 * @return bool
	 */
	public function capture_product( $product_id, array $attachment_ids ) {
		global $wpdb;

		if ( ! $this->handle ) {
			return false;
		}

		$product_id = (int) $product_id;
		$post       = get_post( $product_id );

		if ( ! $post ) {
			return false;
		}

		$record = array(
			'product_id'  => $product_id,
			'post'        => self::export_post_row( $post ),
			'meta'        => self::export_meta( $product_id ),
			'terms'       => self::export_terms( $product_id ),
			'comments'    => self::export_comments( $product_id ),
			'variations'  => array(),
			'attachments' => array(),
		);

		// Variations, with their own meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$variation_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
				$product_id
			)
		);

		foreach ( (array) $variation_ids as $vid ) {
			$vid  = (int) $vid;
			$vpost = get_post( $vid );
			if ( ! $vpost ) {
				continue;
			}
			$record['variations'][] = array(
				'id'   => $vid,
				'post' => self::export_post_row( $vpost ),
				'meta' => self::export_meta( $vid ),
			);
			$this->stats['variations']++;
		}

		// Attachments: metadata plus a physical copy of each file.
		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$apost         = get_post( $attachment_id );
			if ( ! $apost || 'attachment' !== $apost->post_type ) {
				continue;
			}

			$files  = BDC_Query::attachment_files( $attachment_id );
			$stored = array();

			foreach ( $files as $file ) {
				$copy = $this->store_file( $attachment_id, $file );
				if ( $copy ) {
					$stored[] = $copy;
				}
			}

			$record['attachments'][] = array(
				'id'         => $attachment_id,
				'post'       => self::export_post_row( $apost ),
				'meta'       => self::export_meta( $attachment_id ),
				'files'      => $stored,
				'upload_rel' => self::relative_upload_path( get_attached_file( $attachment_id ) ),
			);

			$this->stats['attachments']++;
		}

		$json = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$written = fwrite( $this->handle, $json . "\n" );

		if ( false === $written ) {
			return false;
		}

		$this->stats['products']++;

		return true;
	}

	/**
	 * Copies one physical file into the run's files/ directory.
	 *
	 * @param int    $attachment_id Owning attachment.
	 * @param string $source        Absolute source path.
	 * @return array|null Stored-file descriptor.
	 */
	private function store_file( $attachment_id, $source ) {
		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			return null;
		}

		$rel = self::relative_upload_path( $source );
		if ( '' === $rel ) {
			$rel = (string) $attachment_id . '/' . wp_basename( $source );
		}

		$target = $this->run_dir . '/files/' . $rel;
		$dir    = dirname( $target );

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return null;
		}

		$size = @filesize( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$size = ( false === $size ) ? 0 : (int) $size;

		$this->stats['files']++;
		$this->stats['bytes'] += $size;

		return array(
			'rel'  => $rel,
			'abs'  => $source,
			'size' => $size,
		);
	}

	/**
	 * Path of a file relative to the uploads basedir.
	 *
	 * @param string|false $path Absolute path.
	 * @return string Empty string when outside uploads.
	 */
	public static function relative_upload_path( $path ) {
		if ( ! $path ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( untrailingslashit( $uploads['basedir'] ) ) : '';
		$path    = wp_normalize_path( (string) $path );

		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			return ltrim( substr( $path, strlen( $base ) ), '/' );
		}

		return '';
	}

	/**
	 * Finalizes the run, flushes the manifest and updates the index.
	 *
	 * @param string $status Final status: completed|aborted|failed.
	 * @return array Manifest.
	 */
	public function finish( $status = 'completed' ) {
		if ( $this->handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $this->handle );
			$this->handle = null;
		}

		$manifest = $this->read_manifest( $this->run_id );

		if ( empty( $manifest ) ) {
			return array();
		}

		$manifest['status']      = in_array( $status, array( 'running', 'completed', 'aborted', 'failed' ), true ) ? $status : 'completed';
		$manifest['stats']       = $this->stats;
		$manifest['finished_ts'] = time();

		$this->write_manifest( $manifest );
		$this->index_upsert( $manifest );

		$this->run_id  = '';
		$this->run_dir = '';

		return $manifest;
	}

	/**
	 * Re-opens an existing run so more products can be appended (AJAX batching).
	 *
	 * @param string $run_id Run id.
	 * @return bool
	 */
	public function resume( $run_id ) {
		if ( ! self::is_valid_run_id( $run_id ) ) {
			return false;
		}

		$dir = self::run_dir( $run_id );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return false;
		}

		$manifest = $this->read_manifest( $run_id );
		if ( empty( $manifest ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $dir . '/' . self::RECORDS, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		$this->run_id  = $run_id;
		$this->run_dir = $dir;
		$this->handle  = $handle;
		$this->stats   = isset( $manifest['stats'] ) && is_array( $manifest['stats'] )
			? array_merge(
				array(
					'products'    => 0,
					'variations'  => 0,
					'attachments' => 0,
					'files'       => 0,
					'bytes'       => 0,
				),
				$manifest['stats']
			)
			: array(
				'products'    => 0,
				'variations'  => 0,
				'attachments' => 0,
				'files'       => 0,
				'bytes'       => 0,
			);

		return true;
	}

	/**
	 * Current run id.
	 *
	 * @return string
	 */
	public function get_run_id() {
		return $this->run_id;
	}

	/**
	 * Current accumulated stats.
	 *
	 * @return array
	 */
	public function get_stats() {
		return $this->stats;
	}

	/* --------------------------------------------------------------------- */
	/* Export helpers                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Converts a WP_Post into a plain array.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function export_post_row( $post ) {
		$fields = array(
			'ID',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_title',
			'post_excerpt',
			'post_status',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_content_filtered',
			'post_parent',
			'guid',
			'menu_order',
			'post_type',
			'post_mime_type',
			'comment_count',
		);

		$row = array();
		foreach ( $fields as $field ) {
			$row[ $field ] = isset( $post->$field ) ? $post->$field : '';
		}

		return $row;
	}

	/**
	 * Exports all post meta (raw, unserialized-safe).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{key:string,value:string}>
	 */
	private static function export_meta( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				(int) $post_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'key'   => (string) $row['meta_key'],
				// Store raw string; restore writes it back verbatim.
				'value' => (string) $row['meta_value'],
			);
		}

		return $out;
	}

	/**
	 * Exports taxonomy term assignments.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<int,string>> taxonomy => slugs.
	 */
	private static function export_terms( $post_id ) {
		$out        = array();
		$taxonomies = get_object_taxonomies( 'product', 'names' );

		foreach ( (array) $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( (int) $post_id, $taxonomy, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$out[ $taxonomy ] = array();
			foreach ( $terms as $term ) {
				$out[ $taxonomy ][] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
		}

		return $out;
	}

	/**
	 * Exports comments/reviews.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function export_comments( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID = %d", (int) $post_id ),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$comment_id = (int) $row['comment_ID'];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d",
					$comment_id
				),
				ARRAY_A
			);

			$out[] = array(
				'comment' => $row,
				'meta'    => (array) $meta,
			);
		}

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Manifest & index                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Writes the manifest file for the active (or given) run.
	 *
	 * @param array $manifest Manifest data.
	 * @return bool
	 */
	private function write_manifest( array $manifest ) {
		$dir = self::run_dir( isset( $manifest['run_id'] ) ? $manifest['run_id'] : '' );
		if ( '' === $dir ) {
			return false;
		}

		$json = wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			return false;
		}

		return BDC_Install::write_file( $dir . '/' . self::MANIFEST, $json );
	}

	/**
	 * Reads a manifest from disk.
	 *
	 * @param string $run_id Run id.
	 * @return array Empty array on failure.
	 */
	public function read_manifest( $run_id ) {
		return self::load_manifest( $run_id );
	}

	/**
	 * Static manifest loader.
	 *
	 * @param string $run_id Run id.
	 * @return array
	 */
	public static function load_manifest( $run_id ) {
		$dir = self::run_dir( $run_id );
		if ( '' === $dir ) {
			return array();
		}

		$path = $dir . '/' . self::MANIFEST;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw ) {
			return array();
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Inserts or updates a run in the lightweight index option.
	 *
	 * @param array $manifest Manifest data.
	 * @return void
	 */
	private function index_upsert( array $manifest ) {
		self::index_set( $manifest );
	}

	/**
	 * Static index writer.
	 *
	 * @param array $manifest Manifest data.
	 * @return void
	 */
	public static function index_set( array $manifest ) {
		if ( empty( $manifest['run_id'] ) ) {
			return;
		}

		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$index[ $manifest['run_id'] ] = array(
			'run_id'       => $manifest['run_id'],
			'created_ts'   => isset( $manifest['created_ts'] ) ? (int) $manifest['created_ts'] : time(),
			'expires_ts'   => isset( $manifest['expires_ts'] ) ? (int) $manifest['expires_ts'] : ( time() + ( self::retention_days() * DAY_IN_SECONDS ) ),
			'status'       => isset( $manifest['status'] ) ? (string) $manifest['status'] : 'unknown',
			'restored'     => ! empty( $manifest['restored'] ),
			'user_login'   => isset( $manifest['user_login'] ) ? (string) $manifest['user_login'] : '',
			'mode'         => isset( $manifest['mode'] ) ? (string) $manifest['mode'] : 'delete',
			'stats'        => isset( $manifest['stats'] ) ? (array) $manifest['stats'] : array(),
			// Recorded once, when the run is written, so listing never needs
			// to walk the filesystem.
			'size_on_disk' => isset( $manifest['stats']['bytes'] ) ? (int) $manifest['stats']['bytes'] : 0,
		);

		// Newest first, capped.
		uasort(
			$index,
			static function ( $a, $b ) {
				return (int) $b['created_ts'] <=> (int) $a['created_ts'];
			}
		);
		$index = array_slice( $index, 0, 500, true );

		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Removes a run from the index.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	public static function index_remove( $run_id ) {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			return;
		}
		unset( $index[ $run_id ] );
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Lists known backup runs, newest first, reconciled against disk.
	 *
	 * @return array<int,array>
	 */
	public static function list_runs( $reconcile = false ) {
		$index = get_option( self::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();

		// Reconciling scans the backup directory, so it is opt-in and only
		// performed on the backups screen itself, never on a hot path.
		if ( $reconcile ) {
			$root = BDC_Install::backup_root();

			if ( is_dir( $root ) ) {
				$dirs    = glob( $root . '/bdc-*', GLOB_ONLYDIR );
				$changed = false;

				foreach ( (array) $dirs as $dir ) {
					$run_id = wp_basename( $dir );

					if ( isset( $index[ $run_id ] ) || ! self::is_valid_run_id( $run_id ) ) {
						continue;
					}

					$manifest = self::load_manifest( $run_id );

					if ( ! empty( $manifest ) ) {
						self::index_set( $manifest );
						$changed = true;
					}
				}

				if ( $changed ) {
					$index = get_option( self::INDEX_OPTION, array() );
					$index = is_array( $index ) ? $index : array();
				}
			}
		}

		$out   = array();
		$now   = time();
		$stale = array();

		foreach ( $index as $run_id => $row ) {
			$dir = self::run_dir( $run_id );

			if ( '' === $dir || ! is_dir( $dir ) ) {
				$stale[] = $run_id; // Vanished from disk; drop it from the index.
				continue;
			}

			$row['run_id']     = $run_id;
			$row['dir']        = $dir;
			$row['days_left']  = max( 0, (int) ceil( ( (int) $row['expires_ts'] - $now ) / DAY_IN_SECONDS ) );
			$row['is_expired'] = $now > (int) $row['expires_ts'];

			if ( ! isset( $row['size_on_disk'] ) ) {
				$row['size_on_disk'] = 0;
			}

			$out[] = $row;
		}

		if ( ! empty( $stale ) ) {
			foreach ( $stale as $run_id ) {
				unset( $index[ $run_id ] );
			}
			update_option( self::INDEX_OPTION, $index, false );
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['created_ts'] <=> (int) $a['created_ts'];
			}
		);

		return $out;
	}

	/**
	 * Cheap count of stored runs — reads one option, touches no files.
	 *
	 * @return int
	 */
	public static function count_runs() {
		$index = get_option( self::INDEX_OPTION, array() );

		return is_array( $index ) ? count( $index ) : 0;
	}

	/**
	 * Recursively measures a directory's size.
	 *
	 * @param string $dir Absolute path.
	 * @return int Bytes.
	 */
	public static function dir_size( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$bytes = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += (int) $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			return $bytes;
		}

		return $bytes;
	}

	/* --------------------------------------------------------------------- */
	/* Restore                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Counts how many product records a run contains.
	 *
	 * @param string $run_id Run id.
	 * @return int
	 */
	public static function count_records( $run_id ) {
		$dir = self::run_dir( $run_id );
		if ( '' === $dir ) {
			return 0;
		}

		$path = $dir . '/' . self::RECORDS;
		if ( ! is_file( $path ) ) {
			return 0;
		}

		$count = 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return 0;
		}
		while ( false !== fgets( $handle ) ) {
			$count++;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $count;
	}

	/**
	 * Restores a slice of records from a run.
	 *
	 * Idempotent: re-running the same offsets will not duplicate data, because
	 * restore reuses the original IDs and replaces any row occupying them only
	 * when that row is a leftover of the same product.
	 *
	 * @param string $run_id Run id.
	 * @param int    $offset Zero-based record offset.
	 * @param int    $limit  Records to process.
	 * @return array{done:int,restored:int,skipped:int,failed:int,log:array,eof:bool}
	 */
	public static function restore_batch( $run_id, $offset, $limit ) {
		$result = array(
			'done'     => 0,
			'restored' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'log'      => array(),
			'eof'      => true,
		);

		$dir = self::run_dir( $run_id );
		if ( '' === $dir ) {
			$result['log'][] = __( 'شناسه پشتیبان نامعتبر است.', 'bulk-product-cleaner' );
			return $result;
		}

		$path = $dir . '/' . self::RECORDS;
		if ( ! is_file( $path ) ) {
			$result['log'][] = __( 'فایل رکوردهای پشتیبان یافت نشد.', 'bulk-product-cleaner' );
			return $result;
		}

		$offset = max( 0, (int) $offset );
		$limit  = max( 1, min( 50, (int) $limit ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			$result['log'][] = __( 'باز کردن فایل پشتیبان ناموفق بود.', 'bulk-product-cleaner' );
			return $result;
		}

		// Skip to offset.
		$line_no = 0;
		while ( $line_no < $offset ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return $result;
			}
			$line_no++;
		}

		$processed = 0;
		while ( $processed < $limit ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				$result['eof'] = true;
				break;
			}

			$result['eof'] = false;
			$line          = trim( $line );
			$processed++;
			$result['done']++;

			if ( '' === $line ) {
				$result['skipped']++;
				continue;
			}

			$record = json_decode( $line, true );
			if ( ! is_array( $record ) || empty( $record['product_id'] ) ) {
				$result['failed']++;
				$result['log'][] = __( 'رکورد خراب نادیده گرفته شد.', 'bulk-product-cleaner' );
				continue;
			}

			$outcome = self::restore_record( $record, $dir );

			if ( is_wp_error( $outcome ) ) {
				$result['failed']++;
				$result['log'][] = sprintf(
					/* translators: 1: product ID, 2: error message. */
					__( 'محصول #%1$d بازیابی نشد: %2$s', 'bulk-product-cleaner' ),
					(int) $record['product_id'],
					$outcome->get_error_message()
				);
				continue;
			}

			if ( 'skipped' === $outcome ) {
				$result['skipped']++;
				$result['log'][] = sprintf(
					/* translators: %d: product ID. */
					__( 'محصول #%d از قبل موجود بود — رد شد.', 'bulk-product-cleaner' ),
					(int) $record['product_id']
				);
				continue;
			}

			$result['restored']++;
			$result['log'][] = sprintf(
				/* translators: 1: product ID, 2: product title. */
				__( 'محصول #%1$d («%2$s») بازیابی شد.', 'bulk-product-cleaner' ),
				(int) $record['product_id'],
				isset( $record['post']['post_title'] ) ? (string) $record['post']['post_title'] : ''
			);
		}

		// Detect real EOF when we consumed exactly $limit records.
		if ( $processed === $limit ) {
			$peek          = fgets( $handle );
			$result['eof'] = ( false === $peek );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		// Flag the run here rather than relying on the caller, so every entry
		// point (AJAX, WP-CLI, direct) records completion consistently.
		if ( $result['eof'] && ( $result['restored'] > 0 || $result['skipped'] > 0 ) ) {
			self::mark_restored( $run_id );
		}

		return $result;
	}

	/**
	 * Restores a single product record.
	 *
	 * @param array  $record  Decoded record.
	 * @param string $run_dir Run directory.
	 * @return string|WP_Error 'restored'|'skipped' or error.
	 */
	private static function restore_record( array $record, $run_dir ) {
		$product_id = (int) $record['product_id'];

		if ( $product_id <= 0 || empty( $record['post'] ) || ! is_array( $record['post'] ) ) {
			return new WP_Error( 'bdc_bad_record', __( 'ساختار رکورد نامعتبر است.', 'bulk-product-cleaner' ) );
		}

		// Already present and alive? Do not clobber newer data.
		$existing = get_post( $product_id );
		if ( $existing && 'product' === $existing->post_type ) {
			return 'skipped';
		}

		// 1) Restore attachments first, so _thumbnail_id targets exist.
		$attachments = isset( $record['attachments'] ) && is_array( $record['attachments'] ) ? $record['attachments'] : array();
		foreach ( $attachments as $attachment ) {
			self::restore_attachment( $attachment, $run_dir );
		}

		// 2) Restore the product post row with its original ID.
		$inserted = self::insert_post_row( $record['post'], $product_id );
		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		// 3) Meta.
		self::restore_meta( $product_id, isset( $record['meta'] ) ? $record['meta'] : array() );

		// 4) Terms.
		self::restore_terms( $product_id, isset( $record['terms'] ) ? $record['terms'] : array() );

		// 5) Variations.
		if ( ! empty( $record['variations'] ) && is_array( $record['variations'] ) ) {
			foreach ( $record['variations'] as $variation ) {
				if ( empty( $variation['id'] ) || empty( $variation['post'] ) ) {
					continue;
				}
				$vid = (int) $variation['id'];
				if ( get_post( $vid ) ) {
					continue;
				}
				$ok = self::insert_post_row( $variation['post'], $vid );
				if ( ! is_wp_error( $ok ) ) {
					self::restore_meta( $vid, isset( $variation['meta'] ) ? $variation['meta'] : array() );
				}
			}
		}

		// 6) Comments / reviews.
		if ( ! empty( $record['comments'] ) && is_array( $record['comments'] ) ) {
			foreach ( $record['comments'] as $entry ) {
				self::restore_comment( $entry, $product_id );
			}
		}

		// 7) Rebuild caches and WooCommerce lookup tables.
		clean_post_cache( $product_id );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				// Forces a full save through the CRUD layer: refreshes
				// wc_product_meta_lookup and attribute lookup tables.
				$product->save();
			}
		}

		// Recount terms so category counters are accurate again.
		$taxonomies = array_keys( isset( $record['terms'] ) && is_array( $record['terms'] ) ? $record['terms'] : array() );
		if ( ! empty( $taxonomies ) ) {
			foreach ( $taxonomies as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					$terms = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
						wp_update_term_count_now( $terms, $taxonomy );
					}
				}
			}
		}

		/**
		 * Fires after a product has been fully restored from a backup.
		 *
		 * @param int   $product_id Restored product ID.
		 * @param array $record     The backup record.
		 */
		do_action( 'bdc_product_restored', $product_id, $record );

		return 'restored';
	}

	/**
	 * Inserts a raw post row preserving its original ID.
	 *
	 * @param array $row Post row.
	 * @param int   $id  Desired ID.
	 * @return true|WP_Error
	 */
	private static function insert_post_row( array $row, $id ) {
		global $wpdb;

		$id = (int) $id;

		$data = array(
			'ID'                    => $id,
			'post_author'           => isset( $row['post_author'] ) ? (int) $row['post_author'] : 0,
			'post_date'             => isset( $row['post_date'] ) ? (string) $row['post_date'] : current_time( 'mysql' ),
			'post_date_gmt'         => isset( $row['post_date_gmt'] ) ? (string) $row['post_date_gmt'] : current_time( 'mysql', 1 ),
			'post_content'          => isset( $row['post_content'] ) ? (string) $row['post_content'] : '',
			'post_title'            => isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
			'post_excerpt'          => isset( $row['post_excerpt'] ) ? (string) $row['post_excerpt'] : '',
			'post_status'           => isset( $row['post_status'] ) ? (string) $row['post_status'] : 'draft',
			'comment_status'        => isset( $row['comment_status'] ) ? (string) $row['comment_status'] : 'closed',
			'ping_status'           => isset( $row['ping_status'] ) ? (string) $row['ping_status'] : 'closed',
			'post_password'         => isset( $row['post_password'] ) ? (string) $row['post_password'] : '',
			'post_name'             => isset( $row['post_name'] ) ? (string) $row['post_name'] : '',
			'to_ping'               => isset( $row['to_ping'] ) ? (string) $row['to_ping'] : '',
			'pinged'                => isset( $row['pinged'] ) ? (string) $row['pinged'] : '',
			'post_modified'         => isset( $row['post_modified'] ) ? (string) $row['post_modified'] : current_time( 'mysql' ),
			'post_modified_gmt'     => isset( $row['post_modified_gmt'] ) ? (string) $row['post_modified_gmt'] : current_time( 'mysql', 1 ),
			'post_content_filtered' => isset( $row['post_content_filtered'] ) ? (string) $row['post_content_filtered'] : '',
			'post_parent'           => isset( $row['post_parent'] ) ? (int) $row['post_parent'] : 0,
			'guid'                  => isset( $row['guid'] ) ? (string) $row['guid'] : '',
			'menu_order'            => isset( $row['menu_order'] ) ? (int) $row['menu_order'] : 0,
			'post_type'             => isset( $row['post_type'] ) ? (string) $row['post_type'] : 'product',
			'post_mime_type'        => isset( $row['post_mime_type'] ) ? (string) $row['post_mime_type'] : '',
			'comment_count'         => isset( $row['comment_count'] ) ? (int) $row['comment_count'] : 0,
		);

		$formats = array(
			'%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d',
			'%s', '%s', '%d',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $wpdb->posts, $data, $formats );

		if ( false === $ok ) {
			return new WP_Error(
				'bdc_insert_failed',
				sprintf(
					/* translators: %s: database error. */
					__( 'درج رکورد در دیتابیس ناموفق بود: %s', 'bulk-product-cleaner' ),
					$wpdb->last_error ? $wpdb->last_error : __( 'خطای نامشخص', 'bulk-product-cleaner' )
				)
			);
		}

		clean_post_cache( $id );

		return true;
	}

	/**
	 * Restores meta rows verbatim.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Meta rows.
	 * @return void
	 */
	private static function restore_meta( $post_id, $meta ) {
		global $wpdb;

		if ( ! is_array( $meta ) ) {
			return;
		}

		$post_id = (int) $post_id;

		// Clear anything stale sitting on this ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ), array( '%d' ) );

		foreach ( $meta as $row ) {
			if ( ! isset( $row['key'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => (string) $row['key'],
					'meta_value' => isset( $row['value'] ) ? (string) $row['value'] : '',
				),
				array( '%d', '%s', '%s' )
			);
		}

		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Restores taxonomy assignments, recreating missing terms.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $terms   taxonomy => [ {slug,name}, ... ].
	 * @return void
	 */
	private static function restore_terms( $post_id, $terms ) {
		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $taxonomy => $items ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $items ) ) {
				continue;
			}

			$slugs = array();
			foreach ( $items as $item ) {
				$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
				$name = isset( $item['name'] ) ? (string) $item['name'] : $slug;
				if ( '' === $slug ) {
					continue;
				}

				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term ) {
					$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
					if ( is_wp_error( $created ) ) {
						continue;
					}
				}
				$slugs[] = $slug;
			}

			if ( ! empty( $slugs ) ) {
				wp_set_object_terms( (int) $post_id, $slugs, $taxonomy, false );
			}
		}
	}

	/**
	 * Restores a single comment plus its meta.
	 *
	 * @param array $entry   Comment entry.
	 * @param int   $post_id Parent post ID.
	 * @return void
	 */
	private static function restore_comment( $entry, $post_id ) {
		global $wpdb;

		if ( empty( $entry['comment'] ) || ! is_array( $entry['comment'] ) ) {
			return;
		}

		$comment    = $entry['comment'];
		$comment_id = isset( $comment['comment_ID'] ) ? (int) $comment['comment_ID'] : 0;

		if ( $comment_id > 0 && get_comment( $comment_id ) ) {
			return; // Already there.
		}

		$comment['comment_post_ID'] = (int) $post_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $wpdb->comments, $comment );

		if ( false === $ok ) {
			return;
		}

		$new_id = $comment_id > 0 ? $comment_id : (int) $wpdb->insert_id;

		if ( ! empty( $entry['meta'] ) && is_array( $entry['meta'] ) ) {
			foreach ( $entry['meta'] as $meta_row ) {
				if ( ! isset( $meta_row['meta_key'] ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$wpdb->commentmeta,
					array(
						'comment_id' => $new_id,
						'meta_key'   => (string) $meta_row['meta_key'],
						'meta_value' => isset( $meta_row['meta_value'] ) ? (string) $meta_row['meta_value'] : '',
					),
					array( '%d', '%s', '%s' )
				);
			}
		}

		clean_comment_cache( $new_id );
	}

	/**
	 * Restores an attachment post plus its physical files.
	 *
	 * @param array  $attachment Attachment record.
	 * @param string $run_dir    Run directory.
	 * @return bool
	 */
	private static function restore_attachment( $attachment, $run_dir ) {
		if ( empty( $attachment['id'] ) || empty( $attachment['post'] ) ) {
			return false;
		}

		$attachment_id = (int) $attachment['id'];

		// Put the physical files back first.
		if ( ! empty( $attachment['files'] ) && is_array( $attachment['files'] ) ) {
			foreach ( $attachment['files'] as $file ) {
				if ( empty( $file['rel'] ) || empty( $file['abs'] ) ) {
					continue;
				}

				$source = $run_dir . '/files/' . ltrim( (string) $file['rel'], '/' );
				$target = (string) $file['abs'];

				// Path-traversal guard: the resolved source must stay inside run_dir.
				$real_root = realpath( $run_dir );
				$real_src  = realpath( $source );
				if ( ! $real_root || ! $real_src || 0 !== strpos( $real_src, $real_root ) ) {
					continue;
				}

				if ( is_file( $target ) ) {
					continue; // Never overwrite a live file.
				}

				$dir = dirname( $target );
				if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				@copy( $real_src, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		// Then the database row.
		if ( ! get_post( $attachment_id ) ) {
			$ok = self::insert_post_row( $attachment['post'], $attachment_id );
			if ( is_wp_error( $ok ) ) {
				return false;
			}
			self::restore_meta( $attachment_id, isset( $attachment['meta'] ) ? $attachment['meta'] : array() );
		}

		clean_post_cache( $attachment_id );

		return true;
	}

	/**
	 * Marks a run as restored.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	public static function mark_restored( $run_id ) {
		$manifest = self::load_manifest( $run_id );
		if ( empty( $manifest ) ) {
			return;
		}

		$manifest['restored']     = true;
		$manifest['restored_gmt'] = gmdate( 'Y-m-d H:i:s' );

		$dir = self::run_dir( $run_id );
		if ( '' !== $dir ) {
			$json = wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			if ( false !== $json ) {
				BDC_Install::write_file( $dir . '/' . self::MANIFEST, $json );
			}
		}

		self::index_set( $manifest );
	}

	/* --------------------------------------------------------------------- */
	/* Deletion / retention                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Permanently deletes a backup run directory.
	 *
	 * @param string $run_id Run id.
	 * @return bool
	 */
	public static function delete_run( $run_id ) {
		$dir = self::run_dir( $run_id );

		if ( '' === $dir || ! is_dir( $dir ) ) {
			self::index_remove( $run_id );
			return false;
		}

		// Safety: must live under the backup root.
		$real_root = realpath( BDC_Install::backup_root() );
		$real_dir  = realpath( $dir );
		if ( ! $real_root || ! $real_dir || 0 !== strpos( $real_dir, $real_root ) || $real_dir === $real_root ) {
			return false;
		}

		self::rrmdir( $real_dir );
		self::index_remove( $run_id );

		return ! is_dir( $real_dir );
	}

	/**
	 * Recursively removes a directory.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( $item->isDir() ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
					@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
					@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		} catch ( Exception $e ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Cron callback: deletes backups past their retention window.
	 *
	 * @return int Number of runs purged.
	 */
	public static function purge_expired() {
		$purged = 0;
		$now    = time();

		foreach ( self::list_runs() as $run ) {
			if ( (int) $run['expires_ts'] > $now ) {
				continue;
			}
			if ( self::delete_run( $run['run_id'] ) ) {
				$purged++;
			}
		}

		/**
		 * Fires after the retention purge has run.
		 *
		 * @param int $purged Number of runs removed.
		 */
		do_action( 'bdc_backups_purged', $purged );

		return $purged;
	}
}
