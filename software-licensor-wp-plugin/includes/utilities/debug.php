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