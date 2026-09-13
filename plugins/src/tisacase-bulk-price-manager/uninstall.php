<?php
/**
 * حذف کامل داده‌های افزونه هنگام uninstall.
 *
 * @package TisaCase_Bulk_Price_Manager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// رویدادهای کرون.
wp_clear_scheduled_hook( 'tcbpm_process_scheduled_tick' );
wp_clear_scheduled_hook( 'tcbpm_daily_cleanup' );

// تنظیمات.
delete_option( 'tcbpm_settings' );
delete_option( 'tcbpm_db_version' );

// جدول‌ها.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tisacase_bpm_runs' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tisacase_bpm_log' );
