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
 * Performs the Register Store API Request.
 * 
 * @return Protobuf Returns the decoded protobuf `GetLicenseResponse` message
 */
function software_licensor_register_store_request(
    $store_id,
) {
    $request_proto = new Register_store_request\RegisterStoreRequest();

    software_licensor_update_pubkeys(true);

    list($country, $state) = explode(":", get_option('woocommerce_default_country'));

    $config = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'secp384r1'
    ];
    
    // Generate a new private key
    $private_key_resource = openssl_pkey_new($config);
    
    if ($private_key_resource === false) {
        echo "Failed to generate private key: " . openssl_error_string();
        return;
    }

    software_licensor_save_private_key($private_key_resource);

    $key_details = openssl_pkey_get_details($private_key_resource);
    $public_key_pem = $key_details['key'];

    $request_proto->setPem($public_key_pem);

    $response_proto = new Register_store_request\RegisterStoreResponse();
    $ok = software_licensor_process_request($store_id, 'https://01lzc0nx9e.execute-api.us-east-1.amazonaws.com/v2/register_store_refactor', $request_proto, $response_proto);

    if (!$ok) {
        echo 'Error registering the store';
        error_log('Error registering the store');
        return false;
    }

    $store_id = $response_proto->getStoreId();
    software_licensor_save_store_id($store_id);
    return true;
}

?>