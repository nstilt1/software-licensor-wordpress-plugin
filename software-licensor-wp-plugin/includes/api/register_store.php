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
    $store_id_prefix, 
    $email, 
    $first_name, 
    $last_name, 
    $discord_username, 
    $offline_frequency_hours, 
    $perpetual_expiration_days, 
    $perpetual_frequency_hours, 
    $subscription_expiration_days, 
    $subscription_leniency_offset_hours, 
    $subscription_frequency_hours, 
    $trial_expiration_days, 
    $trial_frequency_hours
) {
    $request_proto = new Register_store_request\RegisterStoreRequest();

    software_licensor_update_pubkeys(true);

    list($country, $state) = explode(":", get_option('woocommerce_default_country'));
    $request_proto->setContactEmail($email);
    $request_proto->setContactFirstName($first_name);
    $request_proto->setContactLastName($last_name);
    $request_proto->setCountry($country);
    $request_proto->setDiscordUsername($discord_username);

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

    $configs_proto = new Register_store_request\Configs();
    $configs_proto->setOfflineLicenseFrequencyHours($offline_frequency_hours);
    $configs_proto->setPerpetualLicenseExpirationDays($perpetual_expiration_days);
    $configs_proto->setPerpetualLicenseFrequencyHours($perpetual_frequency_hours);
    $configs_proto->setSubscriptionLicenseExpirationDays($subscription_expiration_days);
    $configs_proto->setSubscriptionLicenseExpirationLeniencyHours($subscription_leniency_offset_hours);
    $configs_proto->setSubscriptionLicenseFrequencyHours($subscription_frequency_hours);
    $configs_proto->setTrialLicenseExpirationDays($trial_expiration_days);
    $configs_proto->setTrialLicenseFrequencyHours($trial_frequency_hours);
    $request_proto->setConfigs($configs_proto);

    $response_proto = new Register_store_request\RegisterStoreResponse();
    $ok = software_licensor_process_request($store_id_prefix, 'https://01lzc0nx9e.execute-api.us-east-1.amazonaws.com/v2/register_store_refactor', $request_proto, $response_proto);

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