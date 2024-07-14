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
 * Performs the Create License API Request.
 * 
 * @return Protobuf Returns the decoded protobuf `GetLicenseResponse` message
 */
function software_licensor_create_license_request($order_id) {
    $request_proto = new Create_license_request\CreateLicenseRequest();

    global $wpdb;
    $order = wc_get_order($order_id);
    $user = $order->get_user();

    $has_physical_items = false;
    $has_plugins = false;

    // user must have an account to own and manage licenses
    if (!$user) {
        return false;
    }

    $product_info_map = [];

    // some subscription code. This code will require looping over the 
    // subscriptions, getting the item meta (if possible) that relates to
    // software licensor, such as the software_licensor_id. It also 
    // requires getting the subscription period to send in the request, 
    // and having a separate function that updates Software Licensor to 
    // extend the subscription period
    // $subscriptions = wcs_get_subscriptions_for_order($order);
    // foreach ($subscriptions as $subscription) {

    // }

    foreach ($order->get_items() as $item_id => $item) {
        $software_licensor_id = $item->get_meta('software_licensor_id');

        if (!wc_get_product($item->get_product_id())->is_virtual()) {
            $has_physical_items = true;
        }

        if ($software_licensor_id) {
            $product_info = new Create_license_request\ProductInfo();
            $has_plugins = true;
            // check if license type in this order exists already
            if (!array_key_exists($software_licensor_id, $product_info_map)) {
                $license_type = $item->get_meta('license_type');

                if ($license_type == 'perpetual') {
                    $perpetual = new Create_license_request\PerpetualLicense();
                    $perpetual->setQuantity($item->get_quantity());
                    $product_info->setPerpetualLicense($perpetual);
                    $product_info_map[$software_licensor_id] = $product_info;
                } else if ($license_type == 'trial') {
                    $trial = new Create_license_request\TrialLicense();
                    $product_info->setTrialLicense($trial);
                    $product_info_map[$software_licensor_id] = $product_info;
                }
            }
        }
    }

    if ($has_plugins) {
        $request_proto->setProductInfo($product_info_map);

        if (software_licensor_is_sharing_customer_info()) {
            $request_proto->setCustomerEmail($order->get_billing_email());
            $request_proto->setCustomerFirstName($order->get_billing_first_name());
            $request_proto->setCustomerLastName($order->get_billing_last_name());
        } else {
            $request_proto->setCustomerEmail('Not Provided');
            $request_proto->setCustomerFirstName('Not Provided');
            $request_proto->setCustomerLastName('Not Provided');
        }
        $request_proto->setCustomSuccessMessage('');
        $request_proto->setOrderId($order_id);


        $request_proto->setUserId($user->ID);

        $response_proto = new Create_license_request\CreateLicenseResponse();
        $ok = software_licensor_process_request(software_licensor_load_store_id(), 'https://01lzc0nx9e.execute-api.us-east-1.amazonaws.com/v2/create_license_refactor', $request_proto, $response_proto);

        if (!$ok) {
            echo 'Error creating the license';
            wc_add_notice('There was a problem processing the request');
            return false;
        }

        $issues = $response_proto->getIssues();
        $issues_arr = [];
        foreach ($issues as $key => $value) {
            $issues_arr[$key] = $value;
        }

        if (count($issues_arr) > 0) {
            echo 'There were one or more problems processing your license acquisition.';
            $products = software_licensor_get_products_array();
            foreach ($issues_arr as $id => $error) {
                echo $products[$id]['product_name'] . ': ' . $error;
            }
            if (count($issues_arr) == count(array_keys($product_info_map))) {
                return false;
            }
        }

        $license_data = $response_proto->getLicenseInfo();

        $license_proto = new Get_license_request\GetLicenseResponse();
        software_licensor_decode_protobuf_length_delimited($license_proto, $license_data);

        software_licensor_save_license_info($user, $license_proto);

        $license_code = $license_proto->getLicenseCode();

        if (!$has_physical_items) {
            if ($order->get_status() == 'processing') {
                $order->update_status('wc-completed');
            }
        }
        
        $email = $order->get_billing_email();
        echo 'Your license code is: ' . $license_code;
        echo "<h3>Your license code will also be delivered to $email</h3>";
        
    }
}

?>