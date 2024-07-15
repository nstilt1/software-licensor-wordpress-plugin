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
 * Performs the Regenerate License API Request.
 * 
 * @return Protobuf Returns the decoded protobuf `GetLicenseResponse` message
 */
function software_licensor_regenerate_license_request() {
    $user = wp_get_current_user();

    if (!$user) {
        echo 'Please login to access this functionality.';
        wp_die();
    }

    $last_regen_key = 'software_licensor_last_regen';
    $last_regen = (int) get_user_meta($user->ID, $last_regen_key, true);
    if (time() - $last_regen < 60 * 60 * 24 * 14) {
        wp_die('Error: License regen can only be performed once per fortnight.');
    }

    $request_proto = new Regenerate_license_code\RegenerateLicenseCodeRequest();

    $request_proto->setUserId($user->ID);

    $response_proto = new Get_license_request\GetLicenseResponse();
    $ok = software_licensor_process_request(software_licensor_load_store_id(), 'https://01lzc0nx9e.execute-api.us-east-1.amazonaws.com/v2/regenerate_license_code', $request_proto, $response_proto);

    if (!$ok) {
        update_user_meta($user->ID, $last_regen_key, time());
        return false;
    }

    software_licensor_save_license_info($user, $response_proto);
    wp_die('Please refresh the page to view your new license code');
}

?>