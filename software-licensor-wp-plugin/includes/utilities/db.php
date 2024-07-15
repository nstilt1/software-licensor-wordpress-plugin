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

function software_licensor_encrypt_db($id, $data) {
    if ( defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ) {
        $key = LOGGED_IN_KEY;
    } else {
        echo 'LOGGED_IN_KEY is not set in wp-config';
    }
    if ( defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT ) {
        $salt = LOGGED_IN_SALT;
    } else {
        echo 'LOGGED_IN_SALT is not set in wp-config';
        return;
    }
    $nonce = openssl_random_pseudo_bytes(12);
    $symmetric_key = hash_hkdf('sha384', $key, 32, $id, $salt);

    $tag = '';
    $ciphertext = openssl_encrypt($data, 'aes-256-gcm', $symmetric_key, OPENSSL_RAW_DATA, $nonce, $tag);

    return base64_encode($nonce . $ciphertext . $tag);
}

/**
 * Decrypts data in the database.
 * 
 * @return false|string Returns false if there is an issue decrypting the data,
 * or if there was no data to decrypt.
 */
function software_licensor_decrypt_db($id, $data) {
    if (!isset($data) || $data === false || strlen($data) < 12) {
        return false;
    }
    if ( defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ) {
        $key = LOGGED_IN_KEY;
    } else {
        echo 'LOGGED_IN_KEY is not set in wp-config';
        return false;
    }
    if ( defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT ) {
        $salt = LOGGED_IN_SALT;
    } else {
        echo 'LOGGED_IN_SALT is not set in wp-config';
        return false;
    }

    $encrypted = base64_decode($data);
    $nonce = substr($encrypted, 0, 12);
    $ciphertext = substr($encrypted, 12, strlen($encrypted) - 12 - 16);
    $tag = substr($encrypted, -16);
    $symmetric_key = hash_hkdf('sha384', $key, 32, $id, $salt);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $symmetric_key, OPENSSL_RAW_DATA, $nonce, $tag);
    
    if ( $plaintext === false ) {
        return false;
    }

    return $plaintext;
}

/**
 * Encrypts and saves the client's private key in the database.
 */
function software_licensor_save_private_key($private_key) {
    openssl_pkey_export($private_key, $pem);
    $id = 'software_licensor_client_key';
    $encrypted = software_licensor_encrypt_db($id, $pem);
    update_option($id, $encrypted);
}

function software_licensor_load_private_key() {
    $id = 'software_licensor_client_key';
    $encrypted = get_option($id, false);
    
    if ( $encrypted === false ) {
        throw new Exception('No private key found for Software Licensor. You must first fill out the form in WooCommerce>Settings>Integration>Software Licensor');
    }

    $plaintext = software_licensor_decrypt_db($id, $encrypted);
    $private_key = openssl_pkey_get_private($plaintext);

    if ( $private_key === false ) {
        echo 'Failed to get private key';
    }
    return $private_key;
}

function software_licensor_save_store_id($id) {
    update_option('software_licensor_store_id', $id);
}

function software_licensor_load_store_id() {
    return get_option('software_licensor_store_id', false);
}

function software_licensor_save_products_array($data) {
    update_option('software_licensor_products_info', $data);
}

function software_licensor_get_products_array() {
    return get_option('software_licensor_products_info', []);
}

function software_licensor_save_license_info($user, $proto) {
    $binary = software_licensor_encode_protobuf_length_delimited($proto);
    $encrypted = software_licensor_encrypt_db($user->ID, $binary);
    if ($encrypted === false) {
        software_licensor_error_log('Error saving the license');
    } else {
        software_licensor_error_log('License saved: ' . print_r($proto, true));
        software_licensor_error_log('License json: ' . $proto->serializeToJsonString());
    }
    update_user_meta($user->ID, 'software_licensor_license_info', $encrypted);
    update_user_meta($user->ID, 'software_licensor_license_timeout', time());
}

/**
 * Retrieves any cached license info for a specific user as a protobuf message, 
 * or fetches potentially updated license info if enough time has passed since 
 * the last request.
 */
function software_licensor_get_license_info($user) {
    software_licensor_error_log('inside software_licensor_get_license_info');
    $proto = new Get_license_request\GetLicenseResponse();
    $encrypted = get_user_meta($user->ID, 'software_licensor_license_info', true);
    $decrypted = software_licensor_decrypt_db($user->ID, $encrypted);

    $last_check = (int) get_user_meta($user->ID, 'software_licensor_license_timeout', true);
    // check for updated license info if 4 hours have passed
    if ( time() - $last_check > 60 * 60 * 4 ) {
        $new_license_info = software_licensor_get_license_request($user->ID);
        if ($new_license_info != false) {
            software_licensor_save_license_info($user, $new_license_info);
            software_licensor_error_log('received and saved updated license info');
            return $new_license_info;
        }
        software_licensor_error_log('no license was found for the user');
        return false;
    }
    software_licensor_decode_protobuf_length_delimited($proto, $decrypted);
    software_licensor_error_log('it has not yet been 4 hours since the last GetLicense request');
    return $proto;
}

/**
 * Returns whether the store is sharing customer information with Software 
 * Licensor. This information can be displayed to the user through the software.
 */
function software_licensor_is_sharing_customer_info() {
    get_option('software_licensor_is_store_sharing_customer_info', false);
}

/**
 * Sets whether or not the store is sharing customer information
 */
function software_licensor_set_sharing_customer_info($value) {
    update_option('software_licensor_is_store_sharing_customer_info', $value);
}
?>