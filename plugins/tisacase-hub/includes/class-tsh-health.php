<?php
/**
 * پایش سلامت افزونه‌های اختصاصی.
 *
 * همه‌چیز «بررسی ایستا» (static scan) است: فایل‌ها خوانده و الگوها جستجو می‌شوند؛
 هیچ داده‌ای تغییر نمی‌کند و هیچ کوئری روی جداول سفارش اجرا نمی‌شود.
 نتیجه کش می‌شود (۶ ساعت) و امضای فایل‌ها (اندازه + زمان تغییر) در کلید کش است،
 پس با یک ویرایش، گزارش تازه می‌شود.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_Health' ) ) {

	/**
	 * گزارش سلامت.
	 */
	final class TSH_Health {

		const CACHE = 'tsh_health';
		const TTL   = 6 * HOUR_IN_SECONDS;

		/** @var array|null اسکن خام فایل‌ها در همین درخواست. */
		private static $scan = null;

		/**
		 * گزارش کامل.
		 *
		 * @param bool $refresh ساخت دوباره.
		 * @return array<int,array>
		 */
		public static function all( $refresh = false ) {
			$sign = self::signature();
			if ( ! $refresh ) {
				$cached = get_transient( self::CACHE );
				if ( is_array( $cached ) && isset( $cached['sign'] ) && $cached['sign'] === $sign ) {
					return $cached['checks'];
				}
			}
			$checks = self::build();
			set_transient( self::CACHE, array( 'sign' => $sign, 'checks' => $checks ), self::TTL );
			return $checks;
		}

		/**
		 * خلاصه برای نوار بالای هاب.
		 *
		 * @return array{crit:int,warn:int,info:int,ok:int,total:int}
		 */
		public static function summary() {
			$sum = array(
				'crit'  => 0,
				'warn'  => 0,
				'info'  => 0,
				'ok'    => 0,
				'total' => 0,
			);
			foreach ( self::all() as $check ) {
				$sum['total']++;
				$level = $check['level'];
				if ( 'ok' === $level ) {
					$sum['ok']++;
				} elseif ( isset( $sum[ $level ] ) ) {
					$sum[ $level ]++;
				}
			}
			return $sum;
		}

		/**
		 * امضای فایل‌های تحت بررسی (برای ابطال کش).
		 *
		 * @return string
		 */
		private static function signature() {
			$parts = array( TSH_VERSION );
			foreach ( self::dirs() as $dir ) {
				foreach ( self::files( $dir ) as $file ) {
					$parts[] = $file['rel'] . ':' . $file['size'] . ':' . $file['mtime'];
				}
			}
			return md5( implode( '|', $parts ) );
		}

		/**
		 * پوشهٔ افزونه‌هایی که بررسی می‌شوند.
		 *
		 * @return array<int,string>
		 */
		private static function dirs() {
			$dirs = array();
			foreach ( TSH_Registry::items() as $item ) {
				if ( empty( $item['dir'] ) ) {
					continue;
				}
				$dirs[] = $item['dir'];
			}
			return array_values( array_unique( $dirs ) );
		}

		/**
		 * فایل‌های قابل‌بررسی یک افزونه.
		 *
		 * @param string $dir پوشه.
		 * @return array<int,array{path:string,rel:string,ext:string,size:int,mtime:int,src:string}>
		 */
		private static function files( $dir ) {
			static $cache = array();
			if ( isset( $cache[ $dir ] ) ) {
				return $cache[ $dir ];
			}
			$out  = array();
			$root = trailingslashit( WP_PLUGIN_DIR ) . $dir;
			if ( ! is_dir( $root ) ) {
				$cache[ $dir ] = $out;
				return $out;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$skip = array( 'tests', 'vendor', 'node_modules', '.git', 'assets/demo' );
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( ! in_array( $ext, array( 'php', 'css', 'js' ), true ) ) {
					continue;
				}
				if ( $file->getSize() > 600 * 1024 ) {
					continue;
				}
				$rel = str_replace( trailingslashit( WP_PLUGIN_DIR ) . $dir . '/', '', $file->getPathname() );
				foreach ( $skip as $s ) {
					if ( 0 === strpos( $rel, $s . '/' ) ) {
						continue 2;
					}
				}
				$src = @file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $src ) {
					continue;
				}
				$out[] = array(
					'path'  => $file->getPathname(),
					'rel'   => $rel,
					'ext'   => $ext,
					'size'  => $file->getSize(),
					'mtime' => $file->getMTime(),
					'src'   => $src,
				);
			}
			$cache[ $dir ] = $out;
			return $out;
		}

		/**
		 * همهٔ فایل‌ها با برچسب پوشهٔ مادر.
		 *
		 * @return array<string,array<int,array>>
		 */
		private static function scan() {
			if ( null !== self::$scan ) {
				return self::$scan;
			}
			$scan = array();
			foreach ( self::dirs() as $dir ) {
				$scan[ $dir ] = self::files( $dir );
			}
			self::$scan = $scan;
			return $scan;
		}

		/**
		 * ساخت گزارش.
		 *
		 * @return array<int,array>
		 */
		private static function build() {
			$checks = array();

			$checks[] = self::check_nopriv();
			$checks[] = self::check_ajax_guard();
			$checks[] = self::check_rest();
			$checks[] = self::check_zip();
			$checks[] = self::check_uninstall();
			$checks[] = self::check_fonts();
			$checks[] = self::check_hardcoded_css();
			$checks[] = self::check_i18n();
			$checks[] = self::check_hpos();
			$checks[] = self::check_hub_env();

			foreach ( $checks as $i => $c ) {
				$checks[ $i ]['id']    = $c['id'];
				$checks[ $i ]['order'] = in_array( $c['level'], array( 'crit', 'warn' ), true ) ? 0 : 1;
			}
			usort(
				$checks,
				static function ( $a, $b ) {
					$rank = array(
						'crit' => 0,
						'warn' => 1,
						'info' => 2,
						'ok'   => 3,
					);
					$ra    = isset( $rank[ $a['level'] ] ) ? $rank[ $a['level'] ] : 2;
					$rb    = isset( $rank[ $b['level'] ] ) ? $rank[ $b['level'] ] : 2;
					if ( $ra === $rb ) {
						return strcmp( (string) $a['id'], (string) $b['id'] );
					}
					return $ra < $rb ? -1 : 1;
				}
			);
			return $checks;
		}

		/**
		 * دستگیره‌های عمومی (nopriv) که برای کاربر واردنشده بازند.
		 *
		 * @return array
		 */
		private static function check_nopriv() {
			$hits = array();
			foreach ( self::scan() as $dir => $files ) {
				foreach ( $files as $f ) {
					if ( 'php' !== $f['ext'] ) {
						continue;
					}
					if ( preg_match_all( '/(wp_ajax_nopriv_|admin_post_nopriv_)([a-z0-9_\-]+)/i', $f['src'], $m, PREG_OFFSET_CAPTURE ) ) {
						foreach ( $m[2] as $i => $hit ) {
							$hits[] = array(
								'plugin' => $dir,
								'file'   => $f['rel'],
								'line'   => self::line_of( $f['src'], $hit[1] ),
								'text'   => 'nopriv → ' . $hit[0],
							);
						}
					}
				}
			}
			return array(
				'id'     => 'nopriv',
				'level'  => $hits ? 'crit' : 'ok',
				'title'  => __( 'دستگیرهٔ عمومی (بدون لاگین)', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'این اقدام‌ها با URL قابل صدا زدن‌اند و نیازی به ورود ندارند. اگر دادهٔ سفارش/PII می‌دهند، باید نسخهٔ احرازهویت‌شده شوند.', 'tisacase-hub' )
					: __( 'هیچ wp_ajax_nopriv_ یا admin_post_nopriv_ پیدا نشد.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( 'دستگیره را به wp_ajax_ منتقل کنید و در ابتدای تابع current_user_can() + check_ajax_referer() بگذارید.', 'tisacase-hub' ),
			);
		}

		/**
		 * توابعِ وابستهٔ wp_ajax_ که بررسی دسترسی/nonce ندارند.
		 *
		 * @return array
		 */
		private static function check_ajax_guard() {
			$hits  = array();
			$guard = 'current_user_can|is_user_logged_in|check_ajax_referer|check_admin_referer|wp_verify_nonce|self::guard|::guard\(|cap\(\)';
			foreach ( self::scan() as $dir => $files ) {
				foreach ( $files as $f ) {
					if ( 'php' !== $f['ext'] ) {
						continue;
					}
					if ( ! preg_match_all( "/add_action\(\s*['\"]wp_ajax_(?!nopriv)([a-z0-9_\-]+)['\"]\s*,\s*(?:array\(\s*['\"]?([A-Za-z0-9_]+)['\"]?\s*,\s*['\"]([A-Za-z0-9_]+)['\"]|'([A-Za-z0-9_]+)')/i", $f['src'], $m, PREG_SET_ORDER ) ) {
						continue;
					}
					foreach ( $m as $set ) {
						$action = $set[1];
						$func   = ! empty( $set[3] ) ? $set[3] : ( ! empty( $set[4] ) ? $set[4] : '' );
						if ( '' === $func ) {
							continue;
						}
						$body = self::function_body( $f['src'], $func );
						if ( null === $body ) {
							continue; // بدنه پیدا نشد (مثلاً callback در فایل دیگر) — کاری نداریم.
						}
						if ( ! preg_match( '/' . $guard . '/i', $body ) ) {
							$hits[] = array(
								'plugin' => $dir,
								'file'   => $f['rel'],
								'line'   => self::line_of( $f['src'], strpos( $f['src'], 'function ' . $func ) ),
								'text'   => sprintf( /* translators: 1: ajax action, 2: function name */ __( 'اقدام %1$s → تابع %2$s() بدون بررسی دسترسی', 'tisacase-hub' ), $action, $func ),
							);
						}
					}
				}
			}
			return array(
				'id'     => 'ajax_guard',
				'level'  => $hits ? 'warn' : 'ok',
				'title'  => __( 'مهارِ دسترسی در AJAX', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'برخی توابع AJAX هیچ بررسی دسترسی یا nonce ندارند. این الگو همان ریشهٔ نشت PII است.', 'tisacase-hub' )
					: __( 'همهٔ توابع AJAXِ قابل‌شناسایی، بررسی دسترسی یا nonce دارند.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( 'یک helper مشترک بسازید که nonce + capability را با هم چک کند و در ابتداى هر callback صدا زده شود.', 'tisacase-hub' ),
			);
		}

		/**
		 * مسیرهای REST با permission_callback باز.
		 *
		 * @return array
		 */
		private static function check_rest() {
			$hits = array();
			foreach ( self::scan() as $dir => $files ) {
				foreach ( $files as $f ) {
					if ( 'php' !== $f['ext'] || false === strpos( $f['src'], 'register_rest_route' ) ) {
						continue;
					}
					if ( preg_match_all( "/'permission_callback'\s*=>\s*(['\"])__return_true\1/", $f['src'], $m, PREG_OFFSET_CAPTURE ) ) {
						foreach ( $m[0] as $hit ) {
							$hits[] = array(
								'plugin' => $dir,
								'file'   => $f['rel'],
								'line'   => self::line_of( $f['src'], $hit[1] ),
								'text'   => "permission_callback => '__return_true'",
							);
						}
					}
				}
			}
			return array(
				'id'     => 'rest',
				'level'  => $hits ? 'warn' : 'ok',
				'title'  => __( 'مسیر REST باز', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'مسیرهایی که هر کسی می‌تواند بخواند. اگر فقط SKU/پیش‌فرض است قابل‌قبول؛ اگر دادهٔ مشتری است بحرانی.', 'tisacase-hub' )
					: __( 'هیچ permission_callback بازی پیدا نشد.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( "permission_callback را به current_user_can( 'edit_products' ) محدود کنید.", 'tisacase-hub' ),
			);
		}

		/**
		 * باز کردن ZIP بدون مهار حجم/مسیر.
		 *
		 * @return array
		 */
		private static function check_zip() {
			$hits = array();
			foreach ( self::scan() as $dir => $files ) {
				foreach ( $files as $f ) {
					if ( 'php' !== $f['ext'] ) {
						continue;
					}
					$has = false !== strpos( $f['src'], 'extractTo(' );
					$zip = false !== strpos( $f['src'], 'ZipArchive' );
					if ( ! $has && ! $zip ) {
						continue;
					}
					$guarded = (bool) preg_match( '/numFiles|\$zip->stat|MAX_FILES|max_filesize|realpath\(|\.\.\//', $f['src'] );
					if ( $has && ! $guarded ) {
						$hits[] = array(
							'plugin' => $dir,
							'file'   => $f['rel'],
							'line'   => self::line_of( $f['src'], strpos( $f['src'], 'extractTo(' ) ),
							'text'   => __( 'extractTo بدون مهار تعداد/حجم/مسیر', 'tisacase-hub' ),
						);
					} elseif ( $zip && ! $guarded ) {
						$hits[] = array(
							'plugin' => $dir,
							'file'   => $f['rel'],
							'line'   => self::line_of( $f['src'], strpos( $f['src'], 'ZipArchive' ) ),
							'text'   => __( 'ZipArchive بدون بررسی تعداد فایل', 'tisacase-hub' ),
						);
					}
				}
			}
			return array(
				'id'     => 'zip',
				'level'  => $hits ? 'warn' : 'ok',
				'title'  => __( 'Zip بمب و نفوذ مسیر', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'ZIPهای بزرگ یا فایل‌های مسیرساز (/../) می‌توانند هاست را پر کنند یا بیرون از uploads بنویسند.', 'tisacase-hub' )
					: __( 'معماری ZIP یا با مهار حجم/تعداد است یا اصلاً ZIP باز نمی‌شود.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( 'قبل از extractTo: تعداد فایل، حجم مجموع، و realpath داخل بودن در پوشهٔ مقصد را چک کنید.', 'tisacase-hub' ),
			);
		}

		/**
		 * uninstall.php نبود.
		 *
		 * @return array
		 */
		private static function check_uninstall() {
			$hits = array();
			foreach ( self::dirs() as $dir ) {
				$root = trailingslashit( WP_PLUGIN_DIR ) . $dir;
				if ( ! is_dir( $root ) ) {
					continue;
				}
				if ( ! file_exists( $root . '/uninstall.php' ) && ! self::has_uninstall_hook( $dir ) ) {
					$hits[] = array(
						'plugin' => $dir,
						'file'   => 'uninstall.php',
						'line'   => 0,
						'text'   => __( 'هیچ فایل یا هوک حذفی نیست — آپشن‌ها/متاها بعد از حذف می‌مانند', 'tisacase-hub' ),
					);
				}
			}
			return array(
				'id'     => 'uninstall',
				'level'  => $hits ? 'warn' : 'ok',
				'title'  => __( 'پاک‌سازی هنگام حذف', 'tisacase-hub' ),
				'detail' => $hits
					? sprintf( /* translators: %d: number */ _n( '%d افزونه فایل uninstall.php ندارد.', '%d افزونه فایل uninstall.php ندارند.', count( $hits ), 'tisacase-hub' ), count( $hits ) )
					: __( 'همهٔ افزونه‌ها مسیر حذف مشخصی دارند.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( 'یک uninstall.php که با defined( \'WP_UNINSTALL_PLUGIN\' ) || exit شروع می‌شود و فقط کلیدهای اختصاصی همان افزونه را پاک می‌کند.', 'tisacase-hub' ),
			);
		}

		/**
		 * آیا هوک حذف در کد ثبت شده است؟
		 *
		 * @param string $dir پوشه.
		 * @return bool
		 */
		private static function has_uninstall_hook( $dir ) {
			foreach ( self::files( $dir ) as $f ) {
				if ( 'php' === $f['ext'] && false !== strpos( $f['src'], 'register_uninstall_hook' ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * فونت CDN — همان موردی که هاب محلی حل می‌کند.
		 *
		 * @return array
		 */
		private static function check_fonts() {
			$hits = array();
			foreach ( self::scan() as $dir => $files ) {
				foreach ( $files as $f ) {
					if ( preg_match_all( '/fonts\.(googleapis|gstatic)\.com|cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com/i', $f['src'], $m, PREG_OFFSET_CAPTURE ) ) {
						foreach ( $m[0] as $hit ) {
							$hits[] = array(
								'plugin' => $dir,
								'file'   => $f['rel'],
								'line'   => self::line_of( $f['src'], $hit[1] ),
								'text'   => $hit[0],
							);
						}
					}
				}
			}
			$local = TSH_UI::font_status();
			return array(
				'id'     => 'fonts',
				'level'  => $hits ? 'warn' : ( $local['count'] ? 'ok' : 'info' ),
				'title'  => __( 'بارگذاری قلم', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'بعضی افزونه‌ها قلم را از CDN می‌گیرند: با فیلتر بودن گوگل یا کندی، متن می‌پرد و حریم‌خصوصی هم دردسر دارد.', 'tisacase-hub' )
					: ( $local['count']
						? sprintf( /* translators: %s: weights */ __( 'قلم محلی هاب فعال است (وزن‌ها: %s). افزونه‌ها می‌توانند همان را استفاده کنند.', 'tisacase-hub' ), $local['weights'] )
						: __( 'هیچ فایل قلمی در assets/fonts نیست؛ از قلم سیستم استفاده می‌شود. فایل‌های vazirmatn-400.woff2 … vazirmatn-800.woff2 را آنجا بگذارید — تنظیمات لازم نیست.', 'tisacase-hub' ) ),
				'hits'   => $hits,
				'fix'    => __( 'لینک CDN را حذف کنید؛ هاب قلم را روی همان صفحه‌ها بارگذاری می‌کند.', 'tisacase-hub' ),
			);
		}

		/**
		 * استایل هاردکد (خارج از توکن‌ها) — همان چیزی که فاز ۴ تمام می‌کند.
		 *
		 * @return array
		 */
		private static function check_hardcoded_css() {
			$hits = array();
			foreach ( self::scan() as $dir => $files ) {
				foreach ( $files as $f ) {
					$n = 0;
					if ( 'css' === $f['ext'] ) {
						$n += preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $f['src'], $mm );
						$n += preg_match_all( '/border-radius:\s*\d+px/', $f['src'], $mm2 );
					} elseif ( 'php' === $f['ext'] && ( false !== strpos( $f['src'], '<style' ) || false !== stripos( $f['src'], 'background:' ) ) ) {
						$n += preg_match_all( '/#[0-9a-fA-F]{6}\b/', $f['src'], $mm3 );
					}
					if ( $n > 40 ) {
						$hits[] = array(
							'plugin' => $dir,
							'file'   => $f['rel'],
							'line'   => 0,
							'text'   => sprintf( /* translators: %d: count */ __( '%d مقدار هاردکد (رنگ/گوشه)', 'tisacase-hub' ), $n ),
						);
					}
				}
			}
			return array(
				'id'     => 'tokens',
				'level'  => $hits ? 'info' : 'ok',
				'title'  => __( 'خروج از توکن‌ها', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'لایهٔ نرمال‌ساز هاب ظاهر این فایل‌ها را یکدست می‌کند، ولی تا هگزهای خودشان با var() عوض نشود، تغییر رنگ فقط «رویه» است.', 'tisacase-hub' )
					: __( 'هیچ فایل بزرگ هاردکد نشده‌ای پیدا نشد.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( 'در آن فایل‌ها: color/background/border-radius را به var(--tisa-…، fallback) تبدیل کنید.', 'tisacase-hub' ),
			);
		}

		/**
		 * متن‌های بدون ترجمه.
		 *
		 * @return array
		 */
		private static function check_i18n() {
			$hits = array();
			foreach ( self::scan() as $dir => $files ) {
				$calls   = 0;
				$strings = 0;
				foreach ( $files as $f ) {
					if ( 'php' !== $f['ext'] ) {
						continue;
					}
					$calls   += preg_match_all( '/__\(\s*([\'"])/', $f['src'], $m1 );
					$strings += preg_match_all( '/echo\s+[\'"][^\'"]{6,}[\'"]/u', $f['src'], $m2 );
				}
				if ( $calls < 5 && $strings > 3 ) {
					$hits[] = array(
						'plugin' => $dir,
						'file'   => '',
						'line'   => 0,
						'text'   => sprintf( /* translators: 1: calls, 2: strings */ __( 'فقط %1$d فراخوانی ترجمه در برابر %2$d رشتهٔ مستقیم', 'tisacase-hub' ), $calls, $strings ),
					);
				}
			}
			return array(
				'id'     => 'i18n',
				'level'  => $hits ? 'info' : 'ok',
				'title'  => __( 'متن‌های آمادهٔ ترجمه', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'رشته‌ها داخل کد نوشته شده‌اند؛ اگر روزی لازم شد، بدون rebuild قابل ترجمه نیستند.', 'tisacase-hub' )
					: __( 'الگوی ترجمه در همه‌جا رعایت شده به‌نظر می‌رسد.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( 'رشته‌های کاربرپسند را به __() با text domain خود افزونه ببرید.', 'tisacase-hub' ),
			);
		}

		/**
		 * اعلام سازگاری با جداول سفارش (HPOS).
		 *
		 * @return array
		 */
		private static function check_hpos() {
			$hits = array();
			foreach ( self::scan() as $dir => $files ) {
				$declared = false;
				$touches  = false;
				foreach ( $files as $f ) {
					if ( 'php' !== $f['ext'] ) {
						continue;
					}
					if ( false !== strpos( $f['src'], 'before_woocommerce_init' ) || false !== strpos( $f['src'], 'CustomOrdersTableController' ) ) {
						$declared = true;
					}
					if ( preg_match( '/post_type\s*=?\s*[\'"]shop_order[\'"]|FROM\s+\{?\$wpdb->posts/i', $f['src'] ) ) {
						$touches = true;
					}
				}
				if ( $touches && ! $declared ) {
					$hits[] = array(
						'plugin' => $dir,
						'file'   => '',
						'line'   => 0,
						'text'   => __( 'به post_type سفارش دست می‌زند ولی سازگاری HPOS را اعلام نکرده', 'tisacase-hub' ),
					);
				}
			}
			return array(
				'id'     => 'hpos',
				'level'  => $hits ? 'warn' : 'ok',
				'title'  => __( 'سازگاری با جداول سفارش', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'با روشن‌بودن HPOS، کوئری مستقیم روی wp_posts برای سفارش‌ها ممکن است نتیجهٔ اشتباه بدهد.', 'tisacase-hub' )
					: __( 'افزونه‌ای که با سفارش‌ها کار می‌کند، سازگاری اعلام کرده یا اصلاً کوئری خام ندارد.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( "add_action( 'before_woocommerce_init', … ) با declare_compatibility( 'custom_order_tables' ).", 'tisacase-hub' ),
			);
		}

		/**
		 * وضعیت محیط و خودِ هاب.
		 *
		 * @return array
		 */
		private static function check_hub_env() {
			$hits  = array();
			$level = 'ok';
			if ( ! file_exists( TSH_DIR . 'assets/tisacase-ui.css' ) ) {
				$level = 'crit';
				$hits[] = array(
					'plugin' => TSH_SLUG,
					'file'   => 'assets/tisacase-ui.css',
					'line'   => 0,
					'text'   => __( 'فایل استایل زبان طراحی موجود نیست — صفحات افزونه‌ها یکدست نمی‌شوند.', 'tisacase-hub' ),
				);
			}
			$dir = TSH_DIR . 'assets/fonts';
			if ( ! is_dir( $dir ) ) {
				$hits[] = array(
					'plugin' => TSH_SLUG,
					'file'   => 'assets/fonts',
					'line'   => 0,
					'text'   => __( 'پوشهٔ فونت نساخته شده (اختیاری).', 'tisacase-hub' ),
				);
			}
			return array(
				'id'     => 'env',
				'level'  => $level,
				'title'  => __( 'خودِ هاب', 'tisacase-hub' ),
				'detail' => $hits
					? __( 'چیزی در بستهٔ هاب کم است.', 'tisacase-hub' )
					: __( 'بستهٔ هاب کامل است.', 'tisacase-hub' ),
				'hits'   => $hits,
				'fix'    => __( 'زیپ را دوباره و کامل نصب کنید.', 'tisacase-hub' ),
			);
		}

		/**
		 * شمارهٔ خط از آفست.
		 *
		 * @param string $src    متن.
		 * @param int    $offset آفست.
		 * @return int
		 */
		private static function line_of( $src, $offset ) {
			if ( ! is_int( $offset ) || $offset < 1 ) {
				return 0;
			}
			return substr_count( substr( (string) $src, 0, $offset ), "\n" ) + 1;
		}

		/**
		 * بدنهٔ یک تابع/متد با شمارش آکولاد.
		 *
		 * @param string $src  متن فایل.
		 * @param string $name نام تابع.
		 * @return string|null
		 */
		private static function function_body( $src, $name ) {
			$pos = preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\(/', (string) $src, $m, PREG_OFFSET_CAPTURE );
			if ( ! $pos ) {
				return null;
			}
			$start = strpos( (string) $src, '{', $m[0][1] );
			if ( false === $start ) {
				return null;
			}
			$depth = 0;
			$len   = strlen( (string) $src );
			for ( $i = $start; $i < $len; $i++ ) {
				$c = $src[ $i ];
				if ( '{' === $c ) {
					$depth++;
				} elseif ( '}' === $c ) {
					$depth--;
					if ( 0 === $depth ) {
						return substr( (string) $src, $start, $i - $start + 1 );
					}
				}
			}
			return substr( (string) $src, $start, 4000 );
		}
	}
}
