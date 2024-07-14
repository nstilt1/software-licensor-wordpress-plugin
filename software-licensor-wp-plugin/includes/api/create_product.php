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
 * Performs the Create Product API Request.
 * 
 * @return Protobuf Returns the decoded protobuf `GetLicenseResponse` message
 */
function software_licensor_create_product_request(
    $is_offline_allowed, 
    $max_machines, 
    $product_id_prefix, 
    $product_name, 
    $product_version
) {
    $request_proto = new Create_product_request\CreateProductRequest();

    $request_proto->setIsOfflineAllowed($is_offline_allowed);
    $request_proto->setMaxMachinesPerLicense($max_machines);
    $request_proto->setProductIdPrefix($product_id_prefix);
    $request_proto->setProductName($product_name);
    $request_proto->setVersion($product_version);

    $response_proto = new Create_product_request\CreateProductResponse();
    $ok = software_licensor_process_request(software_licensor_load_store_id(), 'https://01lzc0nx9e.execute-api.us-east-1.amazonaws.com/v2/create_plugin_refactor', $request_proto, $response_proto);

    if (!$ok) {
        echo 'Error creating product with Software Licensor';
        return false;
    }

    $product_id = $response_proto->getProductId();
    $product_public_key = $response_proto->getProductPublicKey();

    $new_product_info = array(
        'product_name' => $product_name,
        'public_key' => base64_encode($product_public_key),
        'allows_offline' => $is_offline_allowed,
        'version' => $product_version
    );

    $products = software_licensor_get_products_array();

    if (isset($products[$product_id])) {
        $products[$product_id]['allows_offline'] = $is_offline_allowed;
        $products[$product_id]['version'] = $product_version;
    } else {
        $products[$product_id] = $new_product_info;
    }

    software_licensor_save_products_array($products);

    return $new_product_info;
}

?>