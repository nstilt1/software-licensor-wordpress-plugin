<?php
/**
 * Software Licensor Integration.
 *
 * @package  WC_Software_Licensor_Integration
 * @category Integration
 * @author   Noah Stiltner
 */

use Google\Protobuf\Internal\CodedInputStream;
use Google\Protobuf\Internal\CodedOutputStream;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Decodes a protobuf message with length delimiting.
 * 
 * @param Protobuf $proto a new instance of the desired protobuf message, which 
 * will be modified to contain the decoded protobuf data.
 * @param Binary $data The raw data.
 */
function software_licensor_decode_protobuf_length_delimited(&$proto, $data) {
    $input = new CodedInputStream($data);

    // read length prefix
    $length = 0;
    $input->readVarInt32($length);

    $input->pushLimit($length);

    $proto->parseFromStream($input);
}

/// Encodes a protobuf with length delimiting.
function software_licensor_encode_protobuf_length_delimited($proto) {
    $serialized = $proto->serializeToString();
    $length = strlen($serialized);

    $output_stream = new CodedOutputStream($length + 10);
    $output_stream->writeVarint64($length);
    $output_stream->writeRaw($serialized, $length);
    
    return $output_stream->getData();
}

?>