<?php
/**
 * Uninstall: remove plugin options from the database.
 *
 * @package TisaCase_Product_Description
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tisacase_desc_options' );
