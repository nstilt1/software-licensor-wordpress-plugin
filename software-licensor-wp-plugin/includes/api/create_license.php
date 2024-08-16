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
    error_log('Create_license_request');
    $request_proto = new Create_license_request\CreateLicenseRequest();

    global $wpdb;
    $order = wc_get_order($order_id);
    $user = $order->get_user();

    $has_physical_items = false;
    $has_plugins = false;

    // user must have an account to own and manage licenses
    if (!$user) {
        error_log('user was false');
        return false;
    }

    $product_info_map = [];

    // some subscription code. This code will require looping over the 
    // subscriptions, getting the item meta (if possible) that relates to
    // software licensor, such as the software_licensor_id. It also 
    // requires getting the subscription period to send in the request, 
    // and having a separate function that updates Software Licensor to 
    // extend the subscription period

    // Check if WooCommerce Subscriptions is active
    // if (is_plugin_active('woocommerce-subscriptions/woocommerce-subscriptions.php')) {
    //     // Safe to run subscription-specific code
    //     $subscriptions = wcs_get_subscriptions_for_order($order_id, array('order_type' => 'any'));
    //     foreach ($subscriptions as $subscription) {
    //         // Process each subscription
    //         foreach ($subscription->get_items() as $item_id => $item) {
    //             $product = $item->get_product();
    //             if ($product) {
    //                 $software_licensor_id = $product->get_attribute('software_licensor_id');
    //                 // further processing...
    //             }
    //         }
    //     }
    // } else {
    //     // Handle the case where WooCommerce Subscriptions is not active
    //     echo "WooCommerce Subscriptions is not active.";
    // }

    foreach ($order->get_items() as $item_id => $item) {
        $order_item_product = new WC_Order_Item_Product($item_id);
        $wc_product = wc_get_product($order_item_product->get_product_id());
        if (!$wc_product->is_virtual()) {
            $has_physical_items = true;
        }

        $software_licensor_id = $wc_product->get_attribute('software_licensor_id');
        if (isset($software_licensor_id)) {
            software_licensor_error_log('Software licensor ID found: ' . $software_licensor_id);
            $product_info = new Create_license_request\ProductInfo();
            $has_plugins = true;
            // check if license type in this order exists already
            if (!array_key_exists($software_licensor_id, $product_info_map)) {
                // get license type
                $variation_id = $order_item_product->get_variation_id();
                software_licensor_debug_log('variation id: ' . $variation_id);
                if ($variation_id) {
                    $variation = new WC_Product_Variation($variation_id);
                    $license_type = $variation->get_attribute('license_type');
                    software_licensor_debug_log('license type found in variation: ' . $license_type);
                    software_licensor_debug_log('license type found as pa_license_type: ' . $variation->get_attribute('pa_license_type'));
                    software_licensor_debug_log('variation: ' . print_r($variation, true));
                    software_licensor_debug_log("\n\n\n\n\n\n\n\n\n\n\n\n\n");
                    software_licensor_debug_log('wc_product: ' . $wc_product);
                    if (empty($license_type) || !isset($license_type)) {
                        $license_type = $wc_product->get_attribute('license_type');
                    }
                } else {
                    $license_type = $wc_product->get_attribute('license_type');
                }
                $license_type = strtolower($license_type);

                software_licensor_error_log('License type found in meta: ' . $license_type);

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
        software_licensor_error_log('Product info json array: ' . json_encode($product_info_map));
        $request_proto->setProductInfo($product_info_map);
        //software_licensor_error_log('request_proto->getProductInfo(): ' . print_r($request_proto->getProductInfo()));

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
        software_licensor_error_log('sending create license request');
        $ok = software_licensor_process_request(software_licensor_load_store_id(), 'https://01lzc0nx9e.execute-api.us-east-1.amazonaws.com/v2/create_license_refactor', $request_proto, $response_proto);

        if (!$ok) {
            echo 'Error creating the license';
            error_log('There was an error with the create_license request.');
            wc_add_notice('There was a problem processing the request');
            return false;
        }

        $issues = $response_proto->getIssues();
        $issues_arr = [];
        foreach ($issues as $key => $value) {
            $issues_arr[$key] = $value;
        }

        if (count($issues_arr) > 0) {
            software_licensor_error_log('There were errors with the license creation');
            echo 'There were one or more problems processing your license acquisition.';
            $products = software_licensor_get_products_array();
            foreach ($issues_arr as $id => $error) {
                echo stripslashes($products[$id]['product_name']) . ': ' . $error;
                software_licensor_error_log('Error with ' . $products[$id]['product_name'] . ': ' . $error);
            }
        }

        $license_data = $response_proto->getLicenseInfo();

        $license_proto = new Get_license_request\GetLicenseResponse();
        software_licensor_decode_protobuf_length_delimited($license_proto, $license_data);

        software_licensor_save_license_info($user, $license_proto);

        $license_code = $license_proto->getLicenseCode();
        software_licensor_error_log('license code: ' . $license_code);

        //software_licensor_error_log('License Info: ' . print_r($license_proto->getLicensedProducts(), true));
        if (!$has_physical_items) {
            if ($order->get_status() == 'processing') {
                $order->update_status('wc-completed');
            }
        }
        
        //$email = $order->get_billing_email();
        //echo 'Your license code is: ' . $license_code;
        //echo "<h3>Your license code will also be delivered to $email</h3>";
    } else {
        error_log('software_licensor_id attribute was not found');
    }
}

?>