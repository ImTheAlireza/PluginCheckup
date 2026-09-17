<?php
/**
 * صفحه مدیریت: رابط کاربری، نوار پیشرفت، ETA و اسکریپت jQuery.
 *
 * @package TisaCase_Order_Phone_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Phone_Exporter_Admin_Page' ) ) {

	final class TisaCase_Phone_Exporter_Admin_Page {

		public static function admin_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			// خودترمیمی: اگر رویداد کرون به هر دلیلی حذف شده باشد، دوباره زمان‌بندی می‌شود.
			if ( ! wp_next_scheduled( TisaCase_Phone_Exporter::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', TisaCase_Phone_Exporter::CRON_HOOK );
			}

			$nonce   = wp_create_nonce( TisaCase_Phone_Exporter::NONCE_ACTION );
			$state   = TisaCase_Phone_Exporter_Session::get_state();
			$initial = ! empty( $state ) ? TisaCase_Phone_Exporter_Ajax::response_payload( $state ) : array();

			$json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

			$l10n = array(
				'preparing'    => __( 'در حال آماده‌سازی خروجی...', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'running'      => __( 'در حال پردازش...', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'paused'       => __( 'خروجی نیمه‌کاره است؛ برای ادامه دکمه «ادامه خروجی» را بزنید.', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'doneFiles'    => __( 'عملیات کامل شد — %1 فایل آماده دانلود است.', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'doneEmpty'    => __( 'هیچ شماره معتبری پیدا نشد (%1 سفارش بررسی شد).', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'cancelled'    => __( 'خروجی لغو شد و فایل‌های موقت پاک شدند.', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'stats'        => __( 'سفارش بررسی‌شده: %1 از %2 &nbsp;|&nbsp; شماره معتبر: %3 &nbsp;|&nbsp; بدون شماره معتبر: %4', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'dupes'        => __( 'تکراری حذف‌شده: %1', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'storage'      => __( 'ذخیره سفارش: %1', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'time'         => __( 'زمان سپری‌شده: %1 (تخمین باقی‌مانده: %2)', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'processError' => __( 'خطا در پردازش.', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'startError'   => __( 'شروع خروجی ناموفق بود:', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'ajaxError'    => __( 'AJAX failed:', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'cancelError'  => __( 'لغو خروجی ناموفق بود.', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'unloadMsg'    => __( 'خروجی در حال پردازش است؛ با بستن صفحه متوقف می‌شود (بعداً قابل ادامه است).', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'noSession'    => __( 'خروجی قبلی فعالی وجود ندارد.', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'filesReady'   => __( 'فایل‌های آماده', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'downloadFile' => __( 'دانلود فایل', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
				'numbers'      => __( 'شماره', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
			);
			?>
			<div class="wrap tisa-wrap tisa-phx" dir="rtl">
				<header class="tisa-phx__hero">
					<div class="tisa-phx__hero-mark" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18h2"/></svg>
					</div>
					<div class="tisa-phx__hero-text">
						<h1 class="tisa-phx__title"><?php esc_html_e( 'خروجی شماره تماس سفارش‌ها', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></h1>
						<p class="tisa-phx__sub"><?php echo esc_html( sprintf(
							/* translators: 1: batch size, 2: file size */
							__( 'فرمت 989xxxxxxxxx · هر فایل %2$s شماره · هر گام %1$s سفارش', TisaCase_Phone_Exporter::TEXT_DOMAIN ),
							number_format_i18n( TisaCase_Phone_Exporter::batch_size() ),
							number_format_i18n( TisaCase_Phone_Exporter::file_size() )
						) ); ?></p>
					</div>
					<span class="tisa-phx__hero-pill"><?php echo esc_html( TisaCase_Phone_Exporter_Queries::hpos_enabled() ? 'HPOS' : 'Legacy' ); ?></span>
					<span class="tisa-phx__hero-pill" dir="ltr">v<?php echo esc_html( TISA_PHONE_EXPORTER_VERSION ); ?></span>
				</header>

				<section class="tisa-phx__card">
					<div class="tisa-phx__card-head">
						<span class="tisa-phx__dot" aria-hidden="true"></span>
						<div>
							<h2><?php esc_html_e( 'ساخت خروجی', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></h2>
							<p><?php esc_html_e( 'همهٔ سفارش‌ها (به‌جز سطل زباله) خوانده می‌شود؛ فایل‌ها بدون سرستون، فقط شماره.', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></p>
						</div>
					</div>
					<div class="tisa-phx__card-body">
						<div class="tisa-phx__between">
							<label class="tisa-switch tisa-phx__switch">
								<input type="checkbox" id="tisa-phone-export-dedup" checked>
								<span class="tisa-switch__track" aria-hidden="true"></span>
								<span><?php esc_html_e( 'حذف شماره‌های تکراری', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?> <small><?php esc_html_e( '(هر شماره یک‌بار، مرتب‌شده)', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></small></span>
							</label>

							<div class="tisa-phx__actions">
								<button type="button" class="tisa-btn tisa-btn--ghost" id="tisa-phone-export-reload">
									<?php esc_html_e( 'آخرین وضعیت', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?>
								</button>
								<button type="button" class="tisa-btn tisa-btn--ghost tisa-phx__danger" id="tisa-phone-export-cancel" style="display:none;">
									<?php esc_html_e( 'توقف و پاک‌سازی', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?>
								</button>
								<button type="button" class="tisa-btn tisa-btn--secondary" id="tisa-phone-export-resume" style="display:none;">
									<?php esc_html_e( 'ادامه خروجی', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?>
								</button>
								<button type="button" class="tisa-btn tisa-btn--primary" id="tisa-phone-export-start">
									<?php esc_html_e( 'شروع خروجی جدید', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?>
								</button>
							</div>
						</div>

						<div id="tisa-phone-export-box" class="tisa-phx__box" <?php echo empty( $initial ) ? 'hidden' : ''; ?>>
							<div class="tisa-phx__kpis">
								<div class="tisa-phx__kpi"><span><?php esc_html_e( 'سفارش بررسی‌شده', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></span><b id="tisa-phx-k-processed">۰</b><small id="tisa-phx-k-total"></small></div>
								<div class="tisa-phx__kpi is-ok"><span><?php esc_html_e( 'شماره معتبر', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></span><b id="tisa-phx-k-valid">۰</b></div>
								<div class="tisa-phx__kpi"><span><?php esc_html_e( 'بدون شماره معتبر', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></span><b id="tisa-phx-k-skipped">۰</b></div>
								<div class="tisa-phx__kpi"><span><?php esc_html_e( 'تکراری حذف‌شده', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></span><b id="tisa-phx-k-dupes">—</b></div>
							</div>
							<div class="tisa-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
								<div id="tisa-phone-export-progress" class="tisa-progress__bar" style="width:0;"></div>
							</div>
							<p id="tisa-phone-export-status" class="tisa-progress-text" aria-live="polite"></p>
							<pre id="tisa-phone-export-error" class="tisa-log tisa-phx__err" style="display:none;"></pre>
							<div id="tisa-phone-export-files" class="tisa-phx__files"></div>
						</div>
					</div>
				</section>

				<details class="tisa-phx__help">
					<summary><?php esc_html_e( 'چه چیزی خروجی گرفته می‌شود؟', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></summary>
					<p><?php esc_html_e( 'شماره تماس صورتحساب همهٔ وضعیت‌های ووکامرس (رسیده، در حال انجام، تکمیل‌شده، لغوشده، ناموفق، مرجوعی و…) به 989xxxxxxxxx تبدیل می‌شود؛ سطل زباله شامل نیست. با «حذف تکراری‌ها» هر شماره یک‌بار و مرتب می‌آید. فایل‌ها تا ۲۴ ساعت روی سرور می‌مانند و بعد خودکار پاک می‌شوند.', TisaCase_Phone_Exporter::TEXT_DOMAIN ); ?></p>
				</details>
			</div>

			<script>
			jQuery(function($) {
				'use strict';

				var ajaxUrl       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ), $json_flags ); ?>;
				var nonce         = <?php echo wp_json_encode( $nonce, $json_flags ); ?>;
				var initial       = <?php echo wp_json_encode( $initial, $json_flags ); ?>;
				var L             = <?php echo wp_json_encode( $l10n, $json_flags ); ?>;
				var startAction   = <?php echo wp_json_encode( TisaCase_Phone_Exporter::AJAX_START, $json_flags ); ?>;
				var processAction = <?php echo wp_json_encode( TisaCase_Phone_Exporter::AJAX_PROCESS, $json_flags ); ?>;
				var cancelAction  = <?php echo wp_json_encode( TisaCase_Phone_Exporter::AJAX_CANCEL, $json_flags ); ?>;

				var running        = false;
				var runId          = (initial && initial.run_id) ? String(initial.run_id) : '';
				var lastData       = (initial && initial.processed !== undefined) ? initial : null;
				var startedAt      = null;
				var startProcessed = 0;

				function fa(n) {
					try { return Number(n || 0).toLocaleString('fa-IR'); }
					catch (e) { return String(n || 0); }
				}

				function escapeHtml(v) {
					return $('<div>').text(v == null ? '' : String(v)).html();
				}

				function fmtDur(sec) {
					sec = Math.max(0, Math.round(sec));
					var m = Math.floor(sec / 60), s = sec % 60;
					return m + ':' + (s < 10 ? '0' : '') + s;
				}

				function setRunning(on) {
					running = on;
					$('#tisa-phone-export-start').prop('disabled', on);
					$('#tisa-phone-export-cancel').toggle(on || runId !== '');
					if (on) {
						$('#tisa-phone-export-resume').hide();
					}
				}

				function onCancelled() {
					running = false;
					runId = '';
					lastData = null;
					setRunning(false);
					$('#tisa-phone-export-cancel').hide();
					$('#tisa-phone-export-progress').css('width', '0%');
					$('#tisa-phone-export-files').empty();
					$('#tisa-phone-export-status').html('<strong>' + escapeHtml(L.cancelled) + '</strong>');
					$('#tisa-phone-export-dedup').prop('disabled', false);
				}

				function finishRun() {
					setRunning(false);
				}

				function setError(message, xhr) {
					setRunning(false);
					$('#tisa-phone-export-resume').show().prop('disabled', false);

					$('#tisa-phone-export-status').html(
						'<strong class="is-warn">' + escapeHtml(L.paused) + '</strong>'
					);

					var detail = message || L.processError;
					if (xhr) {
						detail += '\nHTTP: ' + (xhr.status || 0) + ' ' + (xhr.statusText || '');
						if (xhr.responseText) {
							detail += '\n' + String(xhr.responseText).substring(0, 1200);
						}
					}

					$('#tisa-phone-export-error').text(detail).show();
				}

				function render(data) {
					data = data || {};
					if (data.processed !== undefined) { lastData = data; }
					$('#tisa-phone-export-box').removeAttr('hidden').show();

					if (typeof data.dedup !== 'undefined') {
						$('#tisa-phone-export-dedup').prop('checked', !!data.dedup);
					}
					$('#tisa-phone-export-dedup').prop('disabled', runId !== '' && !data.done);

					var total     = parseInt(data.total_orders || 0, 10);
					var processed = parseInt(data.processed || 0, 10);
					var pct       = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : (data.done ? 100 : 0);

					$('#tisa-phone-export-progress').css('width', pct + '%');

					$('#tisa-phx-k-processed').text(fa(processed));
					$('#tisa-phx-k-total').text(total > 0 ? ('از ' + fa(total) + ' · ' + fa(pct) + '٪') : '');
					$('#tisa-phx-k-valid').text(fa(data.valid_phones || 0));
					$('#tisa-phx-k-skipped').text(fa(data.skipped || 0));
					$('#tisa-phx-k-dupes').text(data.dedup ? fa(data.duplicates || 0) : '—');

					var status = '';
					if (running && startedAt && processed > 20) {
						var elapsed = (Date.now() - startedAt) / 1000;
						var done    = processed - startProcessed;
						if (done > 0 && total > processed) {
							var eta = elapsed / done * (total - processed);
							status += L.time.replace('%1', fmtDur(elapsed)).replace('%2', fmtDur(eta));
						} else {
							status += L.time.replace('%1', fmtDur(elapsed)).replace('%2', '—');
						}
					}

					if (data.done) {
						var files = data.files || [];
						if (files.length) {
							status = '<strong class="is-ok">' + L.doneFiles.replace('%1', fa(files.length)) + '</strong>';
						} else {
							status = '<strong class="is-fail">' + L.doneEmpty.replace('%1', fa(processed)) + '</strong>';
						}
						$('#tisa-phone-export-resume').hide();
					} else if (running) {
						status = escapeHtml(L.running) + (status ? ' · ' + status : '');
					} else if (processed > 0) {
						status = '<strong class="is-warn">' + escapeHtml(L.paused) + '</strong>';
						$('#tisa-phone-export-resume').show();
					}

					$('#tisa-phone-export-status').html(status);

					var files = data.files || [];
					if (files.length) {
						var $box = $('#tisa-phone-export-files').empty();
						$('<h2>', { text: L.filesReady, 'class': 'tisa-phx__files-h' }).appendTo($box);

						var $list = $('<div>', { 'class': 'tisa-phx__files-list' }).appendTo($box);

						$.each(files, function(i, file) {
							var $row = $('<div>', { 'class': 'tisa-phx__file' }).appendTo($list);
							$('<span>', { 'class': 'tisa-phx__file-n', text: fa(i + 1) }).appendTo($row);
							$('<span>', { 'class': 'tisa-phx__file-c', text: fa(file.count || 0) + ' ' + L.numbers }).appendTo($row);
							$('<a>', {
								'class': 'tisa-btn tisa-btn--secondary tisa-btn--sm',
								href: String(file.url || ''),
								text: L.downloadFile
							}).appendTo($row);
						});
					} else {
						$('#tisa-phone-export-files').empty();
					}
				}

				function processNext(attempt) {
					if (!running) { return; }
					attempt = attempt || 0;

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						dataType: 'json',
						timeout: 60000,
						data: { action: processAction, nonce: nonce, run_id: runId }
					})
				.done(function(response) {
					// پاسخ قدیمیِ بعد از لغو/توقف؟ نادیده گرفته می‌شود.
					if (!running) { return; }

					if (!response || !response.success) {
						var msg = response && response.data && response.data.message ? response.data.message : L.processError;
						setError(msg, null);
						return;
					}

						var data = response.data || {};
						if (data.cancelled) { onCancelled(); return; }
						if (data.run_id) { runId = String(data.run_id); }

						$('#tisa-phone-export-error').hide().empty();
						render(data);

						if (data.done) { finishRun(); return; }

						setTimeout(function() { processNext(0); }, 50);
					})
					.fail(function(xhr, status, error) {
						// تلاش مجدد با Backoff نمایی؛ درخواست Process بر اساس Cursor تکرارپذیر است.
						if (running && attempt < 3) {
							setTimeout(function() { processNext(attempt + 1); }, 1000 * Math.pow(2, attempt));
							return;
						}
						setError(L.ajaxError + ' ' + status + (error ? ' / ' + error : ''), xhr);
					});
				}

				function startExport() {
					if (running) { return; }

					runId = '';
					setRunning(true);
					$('#tisa-phone-export-resume').hide();
					$('#tisa-phone-export-files').empty();
					$('#tisa-phone-export-error').hide().empty();
					$('#tisa-phone-export-box').removeAttr('hidden').show();
					$('#tisa-phone-export-progress').css('width', '0%');
					$('#tisa-phone-export-status').text(L.preparing);

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						dataType: 'json',
						timeout: 60000,
						data: {
							action: startAction,
							nonce: nonce,
							dedup: $('#tisa-phone-export-dedup').is(':checked') ? 1 : ''
						}
					})
				.done(function(response) {
					// پاسخ قدیمیِ بعد از لغو/توقف؟ نادیده گرفته می‌شود.
					if (!running) { return; }

					if (!response || !response.success) {
						var msg = response && response.data && response.data.message ? response.data.message : L.startError;
						setError(msg, null);
						return;
					}

						var data = response.data || {};
						if (data.run_id) { runId = String(data.run_id); }
						startedAt = Date.now();
						startProcessed = 0;
						$('#tisa-phone-export-error').hide().empty();
						render(data);
						processNext(0);
					})
					.fail(function(xhr, status, error) {
						setError(L.startError + ' ' + status + (error ? ' / ' + error : ''), xhr);
					});
				}

				function resumeExport() {
					if (running || !runId) { return; }

					setRunning(true);
					$('#tisa-phone-export-error').hide().empty();
					startedAt = Date.now();
					startProcessed = lastData ? parseInt(lastData.processed || 0, 10) : 0;
					processNext(0);
				}

				function cancelExport() {
					// حتی اگر run_id در دسترس نیست، سرور جلسه فعال همین کاربر را پاک می‌کند.
					var $btn = $('#tisa-phone-export-cancel').prop('disabled', true);

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						dataType: 'json',
						timeout: 30000,
						data: { action: cancelAction, nonce: nonce, run_id: runId }
					})
					.done(function(response) {
						$btn.prop('disabled', false);
						if (response && response.success && response.data && response.data.cancelled) {
							onCancelled();
						} else {
							setError(L.cancelError, null);
						}
					})
					.fail(function(xhr) {
						$btn.prop('disabled', false);
						setError(L.cancelError, xhr);
					});
				}

				$('#tisa-phone-export-start').on('click', startExport);
				$('#tisa-phone-export-resume').on('click', resumeExport);
				$('#tisa-phone-export-cancel').on('click', cancelExport);

				$('#tisa-phone-export-reload').on('click', function() {
					if (initial && Object.keys(initial).length) {
						runId = initial.run_id ? String(initial.run_id) : '';
						render(initial);
						$('#tisa-phone-export-cancel').toggle(runId !== '');
					} else {
						$('#tisa-phone-export-box').removeAttr('hidden').show();
						$('#tisa-phone-export-status').text(L.noSession);
					}
				});

				$(window).on('beforeunload', function() {
					if (running) { return L.unloadMsg; }
				});

				if (initial && Object.keys(initial).length) {
					render(initial);
					$('#tisa-phone-export-cancel').toggle(runId !== '');
				}
			});
			</script>
			<?php
		}
	}
}
