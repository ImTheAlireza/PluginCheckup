<?php
/**
 * Snapshot / rollback engine.
 *
 * Before every bulk or selective apply, the old product descriptions are
 * captured so the changes can be reverted later from the admin panel.
 *
 * Snapshots are stored in a single, non-autoloaded option so the wp_options
 * table is not loaded on every request.
 *
 * @package TisaCase_Product_Description
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Desc_Backup' ) ) {
	final class TisaCase_Desc_Backup {

		const OPTION        = 'tisacase_desc_backups';
		const MAX_SNAPSHOTS = 10;

		/**
		 * All snapshots, newest created last (we sort by time when displaying).
		 *
		 * @return array
		 */
		public static function all() {
			$b = get_option( self::OPTION, array() );
			return is_array( $b ) ? $b : array();
		}

		/**
		 * Persist snapshots (never autoloaded).
		 */
		private static function save( $backups ) {
			update_option( self::OPTION, $backups, false );
		}

		/**
		 * Create a new (empty) snapshot and return its id.
		 */
		public static function create( $label ) {
			$backups = self::all();
			$id      = 'tisa_' . time() . '_' . wp_rand( 1000, 9999 );

			$backups[ $id ] = array(
				'id'       => $id,
				'label'    => (string) $label,
				'time'     => time(),
				'items'    => array(), // product_id => array( 'old' => ..., 'new' => ... )
				'restored' => false,
			);

			// Keep only the newest snapshots.
			$backups = array_slice( $backups, -self::MAX_SNAPSHOTS, null, true );

			self::save( $backups );

			return $id;
		}

		/**
		 * Record a single product change in a snapshot (first capture wins).
		 */
		public static function capture( $id, $product_id, array $item ) {
			$backups = self::all();
			if ( ! isset( $backups[ $id ] ) ) {
				return false;
			}

			$product_id = (int) $product_id;
			if ( ! isset( $backups[ $id ]['items'][ $product_id ] ) ) {
				$backups[ $id ]['items'][ $product_id ] = $item;
				self::save( $backups );
			}
			return true;
		}

		/**
		 * Get one snapshot or null.
		 */
		public static function get( $id ) {
			$backups = self::all();
			return isset( $backups[ $id ] ) ? $backups[ $id ] : null;
		}

		/**
		 * Delete a snapshot.
		 */
		public static function delete( $id ) {
			$backups = self::all();
			if ( isset( $backups[ $id ] ) ) {
				unset( $backups[ $id ] );
				self::save( $backups );
			}
		}

		/**
		 * Mark a snapshot as restored (kept for history).
		 */
		public static function mark_restored( $id ) {
			$backups = self::all();
			if ( isset( $backups[ $id ] ) ) {
				$backups[ $id ]['restored'] = true;
				self::save( $backups );
			}
		}

		/**
		 * Apply the rules to one product and record the change into a snapshot.
		 *
		 * @return bool True when the product was actually changed.
		 */
		public static function apply_with_backup( $snapshot_id, $product_id, $apply_prep = false ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}

			$options = TisaCase_Desc_Core::get_options();

			$desc_old = $product->get_description();
			$desc_new = TisaCase_Desc_Core::computed_description( $product, $options );

			$prep_key = isset( $options['prep_meta_key'] ) ? trim( (string) $options['prep_meta_key'] ) : '';
			$prep_old = null;
			$prep_new = null;

			if ( $apply_prep && TisaCase_Desc_Core::should_set_prep_time( $product, $options ) ) {
				$prep_old = $product->get_meta( $prep_key );
				$prep_new = (string) $options['prep_value'];
				$product->update_meta_data( $prep_key, $prep_new );
			}

			$changed = false;
			if ( null !== $desc_new ) {
				$product->set_description( $desc_new );
				$changed = true;
			}
			if ( null !== $prep_new ) {
				$changed = true;
			}

			if ( ! $changed ) {
				return false;
			}

			$product->save();

			self::capture( $snapshot_id, $product_id, array(
				'old'      => (string) $desc_old,
				'new'      => ( null !== $desc_new ) ? (string) $desc_new : (string) $desc_old,
				'prep_key' => ( null !== $prep_new ) ? $prep_key : '',
				'prep_old' => ( null !== $prep_new ) ? $prep_old : null,
				'prep_new' => ( null !== $prep_new ) ? $prep_new : null,
			) );

			return true;
		}

		/**
		 * Apply the preparation time only (no description change), with backup.
		 *
		 * @return bool True when the meta was changed.
		 */
		public static function apply_prep_with_backup( $snapshot_id, $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}

			$options = TisaCase_Desc_Core::get_options();
			if ( ! TisaCase_Desc_Core::should_set_prep_time( $product, $options ) ) {
				return false;
			}

			$key      = $options['prep_meta_key'];
			$prep_old = $product->get_meta( $key );
			$desc     = (string) $product->get_description();

			$product->update_meta_data( $key, $options['prep_value'] );
			$product->save();

			self::capture( $snapshot_id, $product_id, array(
				'old'      => $desc,
				'new'      => $desc,
				'prep_key' => $key,
				'prep_old' => $prep_old,
				'prep_new' => (string) $options['prep_value'],
			) );

			return true;
		}

		/**
		 * Restore one batch of products from a snapshot.
		 *
		 * A product is only reverted when its current description still equals
		 * the value we wrote (i.e. nobody edited it manually in the meantime).
		 *
		 * @param string $id        Snapshot id.
		 * @param int    $page      0-based page.
		 * @param int    $batch     Batch size.
		 * @param int    $restored  Output: reverted count.
		 * @param int    $skipped   Output: skipped count (manual edits / missing).
		 * @param int    $processed Output: items processed in this batch.
		 * @return int Remaining items after this batch (0 = done).
		 */
		public static function restore_batch( $id, $page, $batch, &$restored, &$skipped, &$processed ) {
			$restored  = 0;
			$skipped   = 0;
			$processed = 0;

			$snap = self::get( $id );
			if ( ! $snap ) {
				return 0;
			}

			$items = isset( $snap['items'] ) && is_array( $snap['items'] ) ? $snap['items'] : array();
			$slice = array_slice( $items, (int) $page * (int) $batch, (int) $batch, true );

			foreach ( $slice as $product_id => $item ) {
				$processed++;
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					$skipped++;
					continue;
				}

				$do_desc = isset( $item['old'], $item['new'] ) && (string) $item['old'] !== (string) $item['new'];
				$do_prep = ! empty( $item['prep_key'] ) && isset( $item['prep_old'], $item['prep_new'] )
					&& (string) $item['prep_old'] !== (string) $item['prep_new'];

				$restored_any = false;

				// Description revert (only if untouched since we wrote it).
				if ( $do_desc ) {
					if ( $product->get_description() === $item['new'] ) {
						$product->set_description( $item['old'] );
						$restored_any = true;
					} else {
						$skipped++;
						continue;
					}
				}

				// Preparation-time revert (only if untouched since we wrote it).
				if ( $do_prep ) {
					$cur = $product->get_meta( $item['prep_key'] );
					if ( (string) $cur === (string) $item['prep_new'] ) {
						if ( '' === (string) $item['prep_old'] || null === $item['prep_old'] ) {
							$product->delete_meta_data( $item['prep_key'] );
						} else {
							$product->update_meta_data( $item['prep_key'], $item['prep_old'] );
						}
						$restored_any = true;
					} else {
						$skipped++;
						continue;
					}
				}

				if ( $restored_any ) {
					$product->save();
					$restored++;
				}
			}

			return max( 0, count( $items ) - ( ( (int) $page + 1 ) * (int) $batch ) );
		}
	}
}
