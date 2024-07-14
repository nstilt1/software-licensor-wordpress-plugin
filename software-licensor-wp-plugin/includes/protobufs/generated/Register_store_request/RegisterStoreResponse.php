<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: register_store.proto

namespace Register_store_request;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * Generated from protobuf message <code>register_store_request.RegisterStoreResponse</code>
 */
class RegisterStoreResponse extends \Google\Protobuf\Internal\Message
{
    /**
     * Generated from protobuf field <code>string store_id = 1;</code>
     */
    protected $store_id = '';

    /**
     * Constructor.
     *
     * @param array $data {
     *     Optional. Data for populating the Message object.
     *
     *     @type string $store_id
     * }
     */
    public function __construct($data = NULL) {
        \GPBMetadata\RegisterStore::initOnce();
        parent::__construct($data);
    }

    /**
     * Generated from protobuf field <code>string store_id = 1;</code>
     * @return string
     */
    public function getStoreId()
    {
        return $this->store_id;
    }

    /**
     * Generated from protobuf field <code>string store_id = 1;</code>
     * @param string $var
     * @return $this
     */
    public function setStoreId($var)
    {
        GPBUtil::checkString($var, True);
        $this->store_id = $var;

        return $this;
    }

}

