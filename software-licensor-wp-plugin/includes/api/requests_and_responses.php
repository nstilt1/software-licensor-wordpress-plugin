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
 * Gets the PubkeyRepo from Software Licensor and saves PEM encoded keys to the
 * options table.
 */
function software_licensor_update_pubkeys(bool $save_ecdh_key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://software-licensor-public-keys.s3.amazonaws.com/public_keys");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $protobuf_data = curl_exec($ch);
    if ( curl_errno( $ch ) ) {
        echo 'Error: ' . curl_error($ch);
    }
    curl_close($ch);

    $message = new Pubkeys\PubkeyRepo();
    software_licensor_decode_protobuf_length_delimited($message, $protobuf_data);
    if ( $save_ecdh_key == true ) {
        $ecdh_pubkeys = $message->getEcdhKeys();
        $len = count($ecdh_pubkeys);
        $ecdh_pubkey = $ecdh_pubkeys[rand(0, $len - 1)];

        $pem = $ecdh_pubkey->getEcdhPublicKeyPem();
        update_option('software-licensor-ecdh-pubkey', $pem);
        update_option('software-licensor-ecdh-pubkey-id', base64_encode($ecdh_pubkey->getEcdhKeyId()));
    }

    $ecdsa_pubkey = $message->getEcdsaKey();
    $expiration = $ecdsa_pubkey->getExpiration();
    $ecdsa_pubkey_pem = $ecdsa_pubkey->getEcdsaPublicKeyPem();

    update_option('software-licensor-ecdsa-pubkey', $ecdsa_pubkey_pem);
    update_option('software-licensor-ecdsa-pubkey-id', base64_encode($ecdsa_pubkey->getEcdsaKeyId()));
    update_option('software-licensor-ecdsa-pubkey-expiration', $expiration);
    return $message;
}

/**
 * Builds and processes a POST request to Software Licensor's servers.
 * 
 * @param string $store_id The Store ID is either the desired prefix for a store id (during the register store request) or the full store ID for all other requests.
 * @param string $url The URL that is going to have a POST request sent to.
 * @param Protobuf $request_contents The protobuf message being sent in the POST request
 * @param Protobuf $proto_output The expected output of the request
 * @return bool Returns false if there was an issue/error.
 */
function software_licensor_process_request($store_id, $url, $request_contents, &$proto_output) {
    $symmetric_alg = get_option('software_licensor_symmetric_encryption_algorithm', 'chacha20-poly1305');
    $payload = new Request\Request();
    $payload->setClientId($store_id);

    // encrypt data

    // ensure the public keys are up to date
    $software_licensor_ecdh_pubkey = get_option('software-licensor-ecdh-pubkey', false);
    if ( $software_licensor_ecdh_pubkey === false ) {
        software_licensor_update_pubkeys(true);
        $software_licensor_ecdh_pubkey = get_option('software-licensor-ecdh-pubkey');
    } else {
        $ecdsa_expiration = get_option('software-licensor-ecdsa-pubkey-expiration', false);
        if ( (int) $ecdsa_expiration < time() ) {
            software_licensor_update_pubkeys(false);
        }
    }

    $config = [
        "private_key_type" => OPENSSL_KEYTYPE_EC,
        "curve_name" => "secp384r1"
    ];
    $private_key = openssl_pkey_new($config);
    if ($private_key === false) {
        echo 'Failed to generate private key: ' . openssl_error_string();
        exit;
    }

    $software_licensor_pubkey = openssl_pkey_get_public($software_licensor_ecdh_pubkey);
    $client_ecdh_pubkey = openssl_pkey_get_details($private_key)['key'];
    $shared_secret = openssl_pkey_derive($software_licensor_pubkey, $private_key);
    if ($shared_secret === false) {
        throw new Exception('Failed to compute shared secret: ' . openssl_error_string());
    }

    $salt = openssl_random_pseudo_bytes(48);
    $info = 'Software Licensor API Authentication v2';

    if ($symmetric_alg == 'aes-128-gcm') {
        $symmetric_key = hash_hkdf('sha384', $shared_secret, 16, $info, $salt);
    } else {
        $symmetric_key = hash_hkdf('sha384', $shared_secret, 32, $info, $salt);
    }

    $nonce = openssl_random_pseudo_bytes(12);
    $tag = '';
    $serialized_payload = software_licensor_encode_protobuf_length_delimited($request_contents);
    $ciphertext = openssl_encrypt($serialized_payload, $symmetric_alg, $symmetric_key, OPENSSL_RAW_DATA, $nonce, $tag);

    $payload->setData($nonce . $ciphertext . $tag);

    $decryption_info = new Request\DecryptInfo();
    $decryption_info->setEcdhInfo($info);
    $decryption_info->setEcdhSalt($salt);

    $decryption_info->setPem($client_ecdh_pubkey);
    $decryption_info->setServerEcdhKeyId(base64_decode(get_option('software-licensor-ecdh-pubkey-id')));
    $payload->setDecryptionInfo($decryption_info);
    $payload->setServerEcdsaKeyId(base64_decode(get_option('software-licensor-ecdsa-pubkey-id')));
    $server_ecdsa_pubkey_pem = get_option('software-licensor-ecdsa-pubkey');
    $payload->setSymmetricAlgorithm($symmetric_alg);
    $payload->setTimestamp(time());

    // POST request to URL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $binary_payload = software_licensor_encode_protobuf_length_delimited($payload);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $binary_payload);

    // sign the request
    $private_ecdsa_key = software_licensor_load_private_key();
    if ($private_ecdsa_key === false) {
        error_log('private key is false');
        return false;
    }

    $signing_ok = openssl_sign($binary_payload, $signature, $private_ecdsa_key, OPENSSL_ALGO_SHA384);
    if(!$signing_ok) {
        error_log('Error while signing data ' . openssl_error_string());
        return false;
    }

    $base64_signature = base64_encode($signature);
    $base64_signature = rtrim($base64_signature, '=');

    $headers = [
        'Content-Type:application/x-protobuf',
        'X-Signature: ' . $base64_signature
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
        error_log('cURL Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $header_size);

    preg_match_all('/^X-Signature:\s*(.*)$/mi', $headers, $matches);
    $server_signature = $matches[1][0] ?? 'None';
    $body = substr($response, $header_size);

    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);

    if ($status_code != 200) {
        software_licensor_debug_log('Software Licensor Response Status != 200: ' . $body);
        return false;
    }

    $server_ecdsa_pubkey = openssl_pkey_get_public($server_ecdsa_pubkey_pem);
    if (!$server_ecdsa_pubkey) {
        echo 'Public key loading failed: ' . openssl_error_string();
        error_log('Error loading software licensor server public key');
        return false;
    }

    $sig_verification = openssl_verify($body, base64_decode($server_signature), $server_ecdsa_pubkey, OPENSSL_ALGO_SHA384);

    if ($sig_verification == 1) {
        // signature is good
    } else if ($sig_verification == 0) {
        echo 'bad signature';
        error_log('bad server signature');
        return false;
    } else {
        echo 'Error checking signature: ' . openssl_error_string();
        error_log('Error checking signature: ' . openssl_error_string());
        return false;
    }

    $response_proto = new Response\Response();
    software_licensor_decode_protobuf_length_delimited($response_proto, $body);

    $next_key = $response_proto->getNextEcdhKey();
    update_option('software-licensor-ecdh-pubkey', $next_key->getEcdhPublicKeyPem());
    update_option('software-licensor-ecdh-pubkey-id', base64_encode($next_key->getEcdhKeyId()));

    $encrypted_response = $response_proto->getData();
    $nonce = substr($encrypted_response, 0, 12);
    $ciphertext = substr($encrypted_response, 12, strlen($encrypted_response) - 12 - strlen($tag));
    $tag = substr($encrypted_response, 12 + strlen($ciphertext), strlen($tag));
    
    $plaintext = openssl_decrypt($ciphertext, $symmetric_alg, $symmetric_key, OPENSSL_RAW_DATA, substr($encrypted_response, 0, 12), $tag);
    if ($plaintext === false) {
        error_log('unable to decrypt response: ' . openssl_error_string());
        return false;
    }
    software_licensor_decode_protobuf_length_delimited($proto_output, $plaintext);

    return true;
}

?>