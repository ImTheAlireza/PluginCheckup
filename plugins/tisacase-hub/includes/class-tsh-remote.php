<?php
/**
 * دریافت زیپ افزونه از مخزن — با آینه و بدون گیر کردن به بلاک HTTP وردپرس.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_Remote' ) ) {

	/**
	 * دانلود امن زیپ از GitHub / jsDelivr.
	 */
	final class TSH_Remote {

		/**
		 * میزبان‌هایی که هاب اجازهٔ درخواست به آن‌ها را می‌دهد.
		 *
		 * @return array<int,string>
		 */
		public static function hosts() {
			return array(
				'github.com',
				'raw.githubusercontent.com',
				'objects.githubusercontent.com',
				'camo.githubusercontent.com',
				'cdn.jsdelivr.net',
				'fastly.jsdelivr.net',
				'gcore.jsdelivr.net',
				'api.github.com',
			);
		}

		/**
		 * فیلترهای وردپرس تا download_url / wp_safe_remote_get میزبان گیت‌هاب را رد نکنند.
		 *
		 * @return void
		 */
		public static function allow() {
			add_filter( 'http_request_host_is_external', array( __CLASS__, 'host_is_external' ), 10, 2 );
			add_filter( 'http_request_args', array( __CLASS__, 'request_args' ), 10, 2 );
		}

		/**
		 * @param bool   $allow اجازهٔ فعلی.
		 * @param string $host  میزبان.
		 * @return bool
		 */
		public static function host_is_external( $allow, $host ) {
			if ( in_array( strtolower( (string) $host ), self::hosts(), true ) ) {
				return true;
			}
			return $allow;
		}

		/**
		 * @param array  $args آرگومان درخواست.
		 * @param string $url  آدرس.
		 * @return array
		 */
		public static function request_args( $args, $url ) {
			$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( ! in_array( strtolower( $host ), self::hosts(), true ) ) {
				return $args;
			}
			$args['timeout']     = max( 30, isset( $args['timeout'] ) ? (int) $args['timeout'] : 90 );
			$args['redirection'] = 5;
			$args['sslverify']   = true;
			$ua                  = 'TisaCase-Hub/' . ( defined( 'TSH_VERSION' ) ? TSH_VERSION : '1' ) . '; ' . home_url( '/' );
			if ( empty( $args['user-agent'] ) ) {
				$args['user-agent'] = $ua;
			}
			if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			if ( empty( $args['headers']['Accept'] ) ) {
				$args['headers']['Accept'] = 'application/zip,application/octet-stream,*/*';
			}
			return $args;
		}

		/**
		 * فهرست آدرس‌های یک آیتم: تنظیمات هاب، سپس GitHub، سپس jsDelivr.
		 *
		 * @param array $item آیتم registry.
		 * @return array<int,string>
		 */
		public static function mirrors( $item ) {
			$dir = isset( $item['dir'] ) ? (string) $item['dir'] : '';
			if ( '' === $dir ) {
				return array();
			}
			$file = $dir . '.zip';
			$urls = array();
			if ( class_exists( 'TSH_Registry' ) ) {
				$primary = TSH_Registry::zip_url( $item );
				if ( $primary ) {
					$urls[] = $primary;
				}
			}
			$urls[] = 'https://cdn.jsdelivr.net/gh/ImTheAlireza/TisaCaseHub@main/plugins/dist/' . $file;
			$urls[] = 'https://github.com/ImTheAlireza/TisaCaseHub/raw/main/plugins/dist/' . $file;
			$urls[] = 'https://raw.githubusercontent.com/ImTheAlireza/TisaCaseHub/main/plugins/dist/' . $file;

			$out = array();
			foreach ( $urls as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( $url && ! in_array( $url, $out, true ) ) {
					$out[] = $url;
				}
			}
			return $out;
		}

		/**
		 * زیپ را از اولین آدرس سالم می‌گیرد. مسیر فایل موقت یا WP_Error.
		 *
		 * @param array|string $urls یک یا چند آدرس.
		 * @return string|\WP_Error
		 */
		public static function download_zip( $urls ) {
			self::allow();
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$urls = is_array( $urls ) ? $urls : array( $urls );
			$last = null;
			foreach ( $urls as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( ! $url ) {
					continue;
				}
				$got = self::fetch_one( $url );
				if ( ! is_wp_error( $got ) && is_string( $got ) && is_readable( $got ) && filesize( $got ) > 64 ) {
					return $got;
				}
				$last = is_wp_error( $got ) ? $got : new WP_Error( 'tsh_empty_zip', __( 'فایل زیپ خالی رسید.', 'tisacase-hub' ) );
			}
			if ( $last instanceof WP_Error ) {
				return $last;
			}
			return new WP_Error( 'tsh_no_zip', __( 'آدرس زیپی برای این افزونه نیست.', 'tisacase-hub' ) );
		}

		/**
		 * یک آدرس: download_url، بعد wp_remote_get، بعد cURL، بعد file_get_contents.
		 *
		 * @param string $url آدرس.
		 * @return string|\WP_Error مسیر موقت.
		 */
		private static function fetch_one( $url ) {
			$tmp = download_url( $url, 90 );
			if ( ! is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$wp_err = $tmp;

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 90,
					'redirection' => 5,
					'sslverify'   => true,
					'headers'     => array(
						'Accept'     => 'application/zip,application/octet-stream,*/*',
						'User-Agent' => 'TisaCase-Hub/' . ( defined( 'TSH_VERSION' ) ? TSH_VERSION : '1' ),
					),
				)
			);
			$saved = self::store_body( $response, $url );
			if ( ! is_wp_error( $saved ) ) {
				return $saved;
			}

			$direct = self::fetch_direct( $url );
			if ( ! is_wp_error( $direct ) ) {
				return $direct;
			}

			return $wp_err;
		}

		/**
		 * @param mixed  $response پاسخ wp_remote_*.
		 * @param string $url      آدرس.
		 * @return string|\WP_Error
		 */
		private static function store_body( $response, $url ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'tsh_http', sprintf( /* translators: %d: status */ __( 'پاسخ HTTP %d از مخزن.', 'tisacase-hub' ), $code ) );
			}
			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || strlen( $body ) < 64 ) {
				return new WP_Error( 'tsh_empty_zip', __( 'فایل زیپ خالی رسید.', 'tisacase-hub' ) );
			}
			$tmp = wp_tempnam( $url );
			if ( ! $tmp ) {
				return new WP_Error( 'tsh_temp', __( 'فایل موقت ساخته نشد.', 'tisacase-hub' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $tmp, $body ) ) {
				return new WP_Error( 'tsh_temp', __( 'نوشتن فایل موقت ممکن نشد.', 'tisacase-hub' ) );
			}
			return $tmp;
		}

		/**
		 * وقتی هستهٔ وردپرس HTTP را بلوکه کرده، مستقیم می‌گیریم.
		 *
		 * @param string $url آدرس.
		 * @return string|\WP_Error
		 */
		private static function fetch_direct( $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! in_array( $host, self::hosts(), true ) ) {
				return new WP_Error( 'tsh_host', __( 'میزبان مجاز نیست.', 'tisacase-hub' ) );
			}

			$body = '';
			if ( function_exists( 'curl_init' ) ) {
				$ch = curl_init( $url );
				if ( $ch ) {
					curl_setopt_array(
						$ch,
						array(
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_FOLLOWLOCATION => true,
							CURLOPT_MAXREDIRS      => 5,
							CURLOPT_TIMEOUT        => 90,
							CURLOPT_SSL_VERIFYPEER => true,
							CURLOPT_USERAGENT      => 'TisaCase-Hub/' . ( defined( 'TSH_VERSION' ) ? TSH_VERSION : '1' ),
							CURLOPT_HTTPHEADER     => array( 'Accept: application/zip,application/octet-stream,*/*' ),
						)
					);
					$got  = curl_exec( $ch );
					$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
					$cerr = curl_error( $ch );
					curl_close( $ch );
					if ( is_string( $got ) && $got && $code >= 200 && $code < 300 ) {
						$body = $got;
					} elseif ( $cerr ) {
						return new WP_Error( 'tsh_curl', $cerr );
					} else {
						return new WP_Error( 'tsh_http', sprintf( /* translators: %d: status */ __( 'پاسخ HTTP %d از مخزن.', 'tisacase-hub' ), $code ) );
					}
				}
			}

			if ( '' === $body && ini_get( 'allow_url_fopen' ) ) {
				$ctx  = stream_context_create(
					array(
						'http' => array(
							'timeout'    => 90,
							'follow_location' => 1,
							'header'     => "Accept: application/zip,application/octet-stream,*/*\r\nUser-Agent: TisaCase-Hub\r\n",
						),
						'ssl'  => array(
							'verify_peer'      => true,
							'verify_peer_name' => true,
						),
					)
				);
				$got = @file_get_contents( $url, false, $ctx ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_string( $got ) && strlen( $got ) > 64 ) {
					$body = $got;
				}
			}

			if ( strlen( $body ) < 64 ) {
				return new WP_Error( 'tsh_empty_zip', __( 'دانلود مستقیم از مخزن ممکن نشد.', 'tisacase-hub' ) );
			}
			$tmp = wp_tempnam( $url );
			if ( ! $tmp ) {
				return new WP_Error( 'tsh_temp', __( 'فایل موقت ساخته نشد.', 'tisacase-hub' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $tmp, $body );
			return $tmp;
		}
	}
}
