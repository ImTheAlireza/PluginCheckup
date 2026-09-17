<?php
/**
 * WP-CLI commands: wp tisacase repair [--batch=100] [--dry-run]
 *
 * @package TisaCase_Product_Description
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WP_CLI' ) && ! class_exists( 'TisaCase_Desc_CLI' ) ) {
	final class TisaCase_Desc_CLI {

		public static function register() {
			WP_CLI::add_command( 'tisacase repair', array( __CLASS__, 'repair' ), array(
				'shortdesc' => 'اعمال قوانین توضیحات روی همه محصولات.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'تعداد محصول در هر دسته (پیش‌فرض: تنظیمات افزونه).',
						'optional'    => true,
					),
					array(
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'فقط محاسبه، بدون ذخیره تغییرات.',
						'optional'    => true,
					),
				),
			) );
		}

		public static function repair( $args, $assoc_args ) {
			$options = TisaCase_Desc_Core::get_options();
			$batch   = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : max( 1, (int) $options['batch_size'] );
			$dry     = ! empty( $assoc_args['dry-run'] );

			$counted = wc_get_products( array(
				'status'   => TisaCase_Desc_Core::statuses(),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			) );
			$total = isset( $counted->total ) ? (int) $counted->total : 0;

			WP_CLI::log( sprintf( 'مجموع محصولات: %d | حجم دسته: %d%s', $total, $batch, $dry ? ' | حالت آزمایشی' : '' ) );

			$changed = 0;
			$page    = 1;
			$bar     = null;

			if ( class_exists( 'WP_CLI\Utils' ) && method_exists( 'WP_CLI\Utils', 'make_progress_bar' ) ) {
				$bar = \WP_CLI\Utils\make_progress_bar( 'در حال پردازش…', max( 1, $total ) );
			}

			while ( true ) {
				$ids = wc_get_products( array(
					'status'  => TisaCase_Desc_Core::statuses(),
					'limit'   => $batch,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				) );

				if ( empty( $ids ) ) {
					break;
				}

				foreach ( $ids as $product_id ) {
					$product      = wc_get_product( $product_id );
					$desc_needed  = null !== TisaCase_Desc_Core::computed_description( $product, $options );
					$prep_needed  = TisaCase_Desc_Core::should_set_prep_time( $product, $options );

					if ( $desc_needed || $prep_needed ) {
						$changed++;
						if ( ! $dry ) {
							if ( $desc_needed ) {
								$product->set_description( TisaCase_Desc_Core::computed_description( $product, $options ) );
							}
							if ( $prep_needed ) {
								$product->update_meta_data( $options['prep_meta_key'], $options['prep_value'] );
							}
							$product->save();
						}
					}
					if ( $bar ) {
						$bar->tick();
					}
				}

				if ( count( $ids ) < $batch ) {
					break;
				}
				$page++;
			}

			if ( $bar ) {
				$bar->finish();
			}

			WP_CLI::success( sprintf( '%d محصول اصلاح شد%s.', $changed, $dry ? ' (حالت آزمایشی — چیزی ذخیره نشد)' : '' ) );
		}
	}

	TisaCase_Desc_CLI::register();
}
