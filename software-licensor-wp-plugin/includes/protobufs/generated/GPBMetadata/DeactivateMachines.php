<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: deactivate_machines.proto

namespace GPBMetadata;

class DeactivateMachines
{
    public static $is_initialized = false;

    public static function initOnce() {
        $pool = \Google\Protobuf\Internal\DescriptorPool::getGeneratedPool();

        if (static::$is_initialized == true) {
          return;
        }
        $pool->internalAddGeneratedFile(
            '
{
deactivate_machines.protodeactivate_machines"A
DeactivateMachinesRequest
user_id (	
machine_ids (	bproto3'
        , true);

        static::$is_initialized = true;
    }
}

