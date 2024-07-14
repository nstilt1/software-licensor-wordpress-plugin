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
 * Performs the Get License API Request.
 * 
 * @return Protobuf Returns the decoded protobuf `GetLicenseResponse` message
 */
function software_licensor_get_license_request($user_id) {
    $request_proto = new Get_license_request\GetLicenseRequest();

    $request_proto->setUserId($user_id);

    $response_proto = new Get_license_request\GetLicenseResponse();
    $ok = software_licensor_process_request(software_licensor_load_store_id(), 'https://01lzc0nx9e.execute-api.us-east-1.amazonaws.com/v2/get_license_refactor', $request_proto, $response_proto);

    if (!$ok) {
        echo 'Error getting license info';
        return false;
    }

    return $response_proto;
}

?>