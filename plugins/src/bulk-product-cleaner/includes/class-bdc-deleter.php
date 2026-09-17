<?php
/**
 * Deletion engine: safe, hook-aware, backup-first.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BDC_Deleter
 */
class BDC_Deleter {

	/**
	 * Backup engine.
	 *
	 * @var BDC_Backup
	 */
	private $backup;

	/**
	 * Non-fatal messages accumulated during a batch.
	 *
	 * @var array<int,string>
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param BDC_Backup $backup Backup engine.
	 */
	public function __construct( BDC_Backup $backup ) {
		$this->backup = $backup;
	}

	/**
	 * Available deletion modes.
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function modes() {
		return array(
			'trash'        => __( 'انتقال به زباله‌دان (قابل بازگشت از خود وردپرس)', 'bulk-product-cleaner' ),
			'delete'       => __( 'حذف دائم — نگه‌داشتن تصاویر', 'bulk-product-cleaner' ),
			'delete_media' => __( 'حذف دائم + حذف تصاویر', 'bulk-product-cleaner' ),
		);
	}

	/**
	 * Validates a mode slug.
	 *
	 * @param string $mode Candidate.
	 * @return string
	 */
	public static function sanitize_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		return array_key_exists( $mode, self::modes() ) ? $mode : 'trash';
	}

	/**
	 * Collected notices.
	 *
	 * @return array<int,string>
	 */
	public function get_notices() {
		return $this->notices;
	}

	/**
	 * Builds a dry-run preview for a set of products.
	 *
	 * @param int[]  $product_ids Product IDs.
	 * @param string $mode        Deletion mode.
	 * @return array
	 */
	public function preview( array $product_ids, $mode ) {
		$mode        = self::sanitize_mode( $mode );
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );

		BDC_Query::prime_meta( $product_ids );

		$deletes_media       = ( 'delete_media' === $mode );
		$deletes_permanently = ( 'trash' !== $mode );

		$out = array(
			'mode'            => $mode,
			'mode_label'      => self::mode_label( $mode ),
			'deletes_media'   => $deletes_media,
			'is_permanent'    => $deletes_permanently,
			'products'        => 0,
			'variations'      => 0,
			'images'          => 0,
			'images_total'    => 0,
			'images_kept'     => 0,
			'shared'          => 0,
			'files'           => 0,
			'bytes'           => 0,
			'bytes_kept'      => 0,
			'rows'            => array(),
			'skipped'         => 0,
		);

		foreach ( $product_ids as $product_id ) {
			$post = get_post( $product_id );

			if ( ! $post || 'product' !== $post->post_type || ! $this->is_status_allowed( $post->post_status ) ) {
				$out['skipped']++;
				continue;
			}

			$out['products']++;

			$image_ids  = BDC_Query::collect_image_ids( $product_id );
			$variations = $this->get_variation_ids( $product_id );

			$deletable  = array();
			$shared     = array();
			$bytes      = 0;
			$file_count = 0;

			// Always measure what the product owns, so the report can explain
			// what is being kept as well as what is going away.
			$bytes_total = 0;
			foreach ( $image_ids as $image_id ) {
				$bytes_total += BDC_Query::attachment_disk_size( $image_id );
			}

			if ( $deletes_media ) {
				$scope = array_merge( array( $product_id ), $variations );

				foreach ( $image_ids as $image_id ) {
					if ( BDC_Query::is_attachment_shared( $image_id, $scope ) ) {
						$shared[] = $image_id;
						continue;
					}
					$deletable[] = $image_id;
					$file_count += count( BDC_Query::attachment_files( $image_id ) );
					$bytes      += BDC_Query::attachment_disk_size( $image_id );
				}
			}

			$kept = count( $image_ids ) - count( $deletable );

			$out['images']       += count( $deletable );
			$out['images_total'] += count( $image_ids );
			$out['images_kept']  += $kept;
			$out['shared']       += count( $shared );
			$out['files']        += $file_count;
			$out['bytes']        += $bytes;
			$out['bytes_kept']   += ( $bytes_total - $bytes );
			$out['variations']   += count( $variations );

			$out['rows'][] = array(
				'id'           => $product_id,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'images'       => count( $deletable ),
				'images_total' => count( $image_ids ),
				'shared'       => count( $shared ),
				'variations'   => count( $variations ),
				'bytes'        => $bytes,
				'size'         => BDC_Query::format_bytes( $bytes ),
			);
		}

		$out['size']      = BDC_Query::format_bytes( $out['bytes'] );
		$out['size_kept'] = BDC_Query::format_bytes( $out['bytes_kept'] );

		// A plain-language explanation of why the numbers look the way they do.
		if ( ! $deletes_permanently ) {
			$out['notice'] = __( 'حالت «انتقال به زباله‌دان» انتخاب شده است: هیچ تصویری حذف نمی‌شود، هیچ فضایی آزاد نمی‌گردد و محصولات قابل بازگردانی می‌مانند. واریانت‌ها همراه محصول به زباله‌دان می‌روند.', 'bulk-product-cleaner' );
		} elseif ( ! $deletes_media ) {
			$out['notice'] = sprintf(
				/* translators: 1: number of images, 2: human-readable size. */
				__( 'حالت «حذف دائم — نگه‌داشتن تصاویر» انتخاب شده است: %1$s تصویر (%2$s) در کتابخانه رسانه باقی می‌ماند و فضایی آزاد نمی‌شود. برای آزاد شدن فضا، حالت «حذف دائم + حذف تصاویر» را انتخاب کنید.', 'bulk-product-cleaner' ),
				number_format_i18n( $out['images_total'] ),
				BDC_Query::format_bytes( $out['bytes_kept'] )
			);
		} elseif ( 0 === $out['images_total'] ) {
			$out['notice'] = __( 'هیچ‌کدام از محصولات انتخاب‌شده تصویری ندارند، بنابراین فضایی آزاد نمی‌شود.', 'bulk-product-cleaner' );
		} elseif ( 0 === $out['images'] && $out['shared'] > 0 ) {
			$out['notice'] = __( 'تمام تصاویر این محصولات در جای دیگری هم استفاده شده‌اند، بنابراین همگی حفظ می‌شوند و فضایی آزاد نمی‌گردد.', 'bulk-product-cleaner' );
		} else {
			$out['notice'] = '';
		}

		return $out;
	}

	/**
	 * Human-readable label for a mode slug.
	 *
	 * @param string $mode Mode slug.
	 * @return string
	 */
	public static function mode_label( $mode ) {
		$modes = self::modes();
		$mode  = self::sanitize_mode( $mode );

		return isset( $modes[ $mode ] ) ? $modes[ $mode ] : $mode;
	}

	/**
	 * Deletes a batch of products.
	 *
	 * @param int[]  $product_ids Product IDs.
	 * @param string $mode        Deletion mode.
	 * @param bool   $do_backup   Whether to snapshot before deleting.
	 * @return array Batch result.
	 */
	public function delete_batch( array $product_ids, $mode, $do_backup = true ) {
		$mode        = self::sanitize_mode( $mode );
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );

		$this->notices = array();

		$result = array(
			'ok'      => 0,
			'fail'    => 0,
			'skipped' => 0,
			'images'  => 0,
			'files'   => 0,
			'bytes'   => 0,
			'log'     => array(),
		);

		BDC_Query::prime_meta( $product_ids );

		$started    = microtime( true );
		// Stay well inside whatever max_execution_time this host allows rather
		// than trusting a fixed 20s. Batches are larger now, so this guard is
		// what keeps a slow host from ever hitting a hard timeout: it closes
		// the batch early and the browser sends the remainder.
		$max_exec = (float) ini_get( 'max_execution_time' );
		$ceiling  = ( $max_exec > 0 ) ? ( $max_exec * 0.6 ) : 20.0;
		$ceiling  = max( 5.0, min( 20.0, $ceiling ) );

		$time_limit = (float) apply_filters( 'bdc_batch_time_limit', $ceiling );

		foreach ( $product_ids as $product_id ) {
			// Bail out cleanly before the web server does it for us. The
			// browser simply sends the remainder in the next batch.
			if ( ( microtime( true ) - $started ) > $time_limit ) {
				$result['log'][] = array(
					'id'      => 0,
					'state'   => 'skip',
					'message' => __( 'دسته زودتر بسته شد تا از timeout جلوگیری شود؛ ادامه در دسته بعدی.', 'bulk-product-cleaner' ),
				);
				break;
			}

			if ( self::memory_exhausted() ) {
				$result['log'][] = array(
					'id'      => 0,
					'state'   => 'skip',
					'message' => __( 'دسته به دلیل نزدیک شدن به سقف حافظه زودتر بسته شد؛ ادامه در دسته بعدی.', 'bulk-product-cleaner' ),
				);
				break;
			}

			$single = $this->delete_single( $product_id, $mode, $do_backup );

			if ( is_wp_error( $single ) ) {
				$result['fail']++;
				$result['log'][] = array(
					'id'      => $product_id,
					'state'   => 'fail',
					'message' => $single->get_error_message(),
				);
				continue;
			}

			if ( 'skipped' === $single['state'] ) {
				$result['skipped']++;
				$result['log'][] = array(
					'id'      => $product_id,
					'state'   => 'skip',
					'message' => $single['message'],
				);
				continue;
			}

			$result['ok']++;
			$result['images'] += $single['images'];
			$result['files']  += $single['files'];
			$result['bytes']  += $single['bytes'];
			$result['log'][]   = array(
				'id'      => $product_id,
				'state'   => 'ok',
				'message' => $single['message'],
			);
		}

		$result['size']    = BDC_Query::format_bytes( $result['bytes'] );
		$result['notices'] = $this->notices;

		// WordPress accumulates every post/meta row it touches; on a long run
		// that is the single biggest source of memory growth.
		self::flush_runtime_caches();

		return $result;
	}

	/**
	 * True when the process is close enough to the memory ceiling that
	 * continuing would risk a fatal error.
	 *
	 * @return bool
	 */
	public static function memory_exhausted() {
		$limit = self::memory_limit_bytes();

		if ( $limit <= 0 ) {
			return false; // Unlimited.
		}

		return memory_get_usage( true ) > ( $limit * 0.85 );
	}

	/**
	 * PHP memory limit in bytes, 0 when unlimited.
	 *
	 * @return int
	 */
	private static function memory_limit_bytes() {
		$raw = ini_get( 'memory_limit' );

		if ( false === $raw || '' === $raw || '-1' === (string) $raw ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $raw );
	}

	/**
	 * Drops the caches that grow during a long batch.
	 *
	 * @return void
	 */
	public static function flush_runtime_caches() {
		global $wpdb, $wp_object_cache;

		// Query log only exists when SAVEQUERIES is on, but it is unbounded.
		if ( ! empty( $wpdb->queries ) ) {
			$wpdb->queries = array();
		}

		if ( is_object( $wp_object_cache ) ) {
			foreach ( array( 'posts', 'post_meta', 'terms', 'term_meta', 'comment', 'comment_meta' ) as $group ) {
				if ( isset( $wp_object_cache->cache[ $group ] ) ) {
					$wp_object_cache->cache[ $group ] = array();
				}
			}

			// Non-persistent caches only; never wipe Redis/Memcached content.
			if ( isset( $wp_object_cache->group_ops ) ) {
				$wp_object_cache->group_ops = array();
			}
		}
	}

	/**
	 * Deletes one product, using WooCommerce/WordPress APIs so every hook fires.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $mode       Deletion mode.
	 * @param bool   $do_backup  Snapshot first.
	 * @return array|WP_Error
	 */
	public function delete_single( $product_id, $mode, $do_backup = true ) {
		$product_id = (int) $product_id;
		$post       = get_post( $product_id );

		if ( ! $post || 'product' !== $post->post_type ) {
			return array(
				'state'   => 'skipped',
				'message' => __( 'محصول یافت نشد.', 'bulk-product-cleaner' ),
				'images'  => 0,
				'files'   => 0,
				'bytes'   => 0,
			);
		}

		if ( ! $this->is_status_allowed( $post->post_status ) ) {
			return array(
				'state'   => 'skipped',
				/* translators: %s: post status. */
				'message' => sprintf( __( 'وضعیت «%s» مجاز نیست — رد شد.', 'bulk-product-cleaner' ), $post->post_status ),
				'images'  => 0,
				'files'   => 0,
				'bytes'   => 0,
			);
		}

		/**
		 * Fires before a product is deleted by the cleaner.
		 *
		 * @param int    $product_id Product ID.
		 * @param string $mode       Deletion mode.
		 */
		do_action( 'bdc_before_delete_product', $product_id, $mode );

		// ------------------------------------------------------------------
		// Trash mode: reversible, nothing else to do.
		// ------------------------------------------------------------------
		if ( 'trash' === $mode ) {
			if ( 'trash' === $post->post_status ) {
				return array(
					'state'   => 'skipped',
					'message' => __( 'از قبل در زباله‌دان است.', 'bulk-product-cleaner' ),
					'images'  => 0,
					'files'   => 0,
					'bytes'   => 0,
				);
			}

			$trashed = wp_trash_post( $product_id );

			if ( ! $trashed ) {
				return new WP_Error( 'bdc_trash_failed', __( 'انتقال به زباله‌دان ناموفق بود.', 'bulk-product-cleaner' ) );
			}

			do_action( 'bdc_after_delete_product', $product_id, $mode, array() );

			return array(
				'state'   => 'deleted',
				'message' => __( 'به زباله‌دان منتقل شد.', 'bulk-product-cleaner' ),
				'images'  => 0,
				'files'   => 0,
				'bytes'   => 0,
			);
		}

		// ------------------------------------------------------------------
		// Permanent modes.
		// ------------------------------------------------------------------
		$delete_media = ( 'delete_media' === $mode );
		$image_ids    = BDC_Query::collect_image_ids( $product_id );
		$variations   = $this->get_variation_ids( $product_id );

		// Decide which attachments may actually go.
		$removable = array();
		$preserved = 0;

		if ( $delete_media ) {
			$scope = array_merge( array( $product_id ), $variations );
			foreach ( $image_ids as $image_id ) {
				if ( BDC_Query::is_attachment_shared( $image_id, $scope ) ) {
					$preserved++;
					continue;
				}
				$removable[] = $image_id;
			}
		}

		// Measure before anything disappears.
		$bytes = 0;
		$files = 0;
		foreach ( $removable as $image_id ) {
			$bytes += BDC_Query::attachment_disk_size( $image_id );
			$files += count( BDC_Query::attachment_files( $image_id ) );
		}

		// --- BACKUP FIRST. No backup, no deletion. ---
		if ( $do_backup ) {
			$captured = $this->backup->capture_product( $product_id, $removable );

			if ( ! $captured ) {
				return new WP_Error(
					'bdc_backup_failed',
					__( 'پشتیبان‌گیری ناموفق بود — برای ایمنی، حذف انجام نشد.', 'bulk-product-cleaner' )
				);
			}
		}

		// 1) Variations, through the CRUD layer.
		if ( function_exists( 'wc_get_product' ) ) {
			foreach ( $variations as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					$variation->delete( true );
				} else {
					wp_delete_post( $variation_id, true );
				}
			}
		} else {
			foreach ( $variations as $variation_id ) {
				wp_delete_post( $variation_id, true );
			}
		}

		// 2) The product itself.
		$deleted = false;

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->delete( true );
				$deleted = ! get_post( $product_id );
			}
		}

		if ( ! $deleted ) {
			$deleted = (bool) wp_delete_post( $product_id, true );
		}

		if ( ! $deleted ) {
			return new WP_Error( 'bdc_delete_failed', __( 'حذف محصول ناموفق بود.', 'bulk-product-cleaner' ) );
		}

		// 3) Attachments (files included) — only the non-shared ones.
		$removed_images = 0;
		foreach ( $removable as $image_id ) {
			$gone = wp_delete_attachment( $image_id, true );
			if ( $gone ) {
				$removed_images++;
			} else {
				$this->notices[] = sprintf(
					/* translators: %d: attachment ID. */
					__( 'تصویر #%d حذف نشد (احتمالاً مشکل دسترسی فایل).', 'bulk-product-cleaner' ),
					$image_id
				);
			}
		}

		if ( $preserved > 0 ) {
			$this->notices[] = sprintf(
				/* translators: 1: count, 2: product ID. */
				_n(
					'%1$d تصویر محصول #%2$d چون جای دیگری استفاده شده بود حفظ شد.',
					'%1$d تصویر محصول #%2$d چون جای دیگری استفاده شده بودند حفظ شدند.',
					$preserved,
					'bulk-product-cleaner'
				),
				$preserved,
				$product_id
			);
		}

		/**
		 * Fires after a product has been permanently deleted.
		 *
		 * @param int   $product_id Product ID.
		 * @param string $mode      Deletion mode.
		 * @param int[] $removable  Attachment IDs that were removed.
		 */
		do_action( 'bdc_after_delete_product', $product_id, $mode, $removable );

		$message = $delete_media
			? sprintf(
				/* translators: 1: image count, 2: human size. */
				__( 'حذف شد — %1$d تصویر (%2$s).', 'bulk-product-cleaner' ),
				$removed_images,
				BDC_Query::format_bytes( $bytes )
			)
			: __( 'حذف شد — تصاویر دست‌نخورده ماندند.', 'bulk-product-cleaner' );

		return array(
			'state'   => 'deleted',
			'message' => $message,
			'images'  => $removed_images,
			'files'   => $files,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Whether a status may be targeted.
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	private function is_status_allowed( $status ) {
		return in_array( (string) $status, BDC_Query::allowed_statuses(), true );
	}

	/**
	 * Child variation IDs of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	private function get_variation_ids( $product_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
				(int) $product_id
			)
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}
}
