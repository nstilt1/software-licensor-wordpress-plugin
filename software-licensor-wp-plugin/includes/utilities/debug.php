<?php
/**
 * Software Licensor Integration.
 *
 * @package  WC_Software_Licensor_Integration
 * @category Integration
 * @author   Noah Stiltner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Conditionally outputs some text to the error log.
 * 
 * Once this plugin is confirmed to be working, we can change the condition to 
 * false.
 */
function software_licensor_error_log($text) {
    if (false) {
        error_log($text);
    }
}

/**
 * Conditionally writes debug logs to a file.
 * 
 * This is useful if another plugin is causing lots of messages to be shown in
 * the standard error log file, such as a plugin that wasn't designed for PHP 
 * 8.0.
 */
function software_licensor_debug_log($text) {
    if (false) {
        $log_dir = WP_CONTENT_DIR . '/software_licensor_logs';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . '/debug.log';
        $log_message = date('Y-m-d H:i:s') . $text . "\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}