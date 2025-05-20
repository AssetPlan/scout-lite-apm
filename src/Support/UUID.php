<?php

namespace AssetPlan\ScoutLiteAPM\Support;

use Exception;

class UUID
{
    public static function v4()
    {
        $data = openssl_random_pseudo_bytes(16);

        if ($data === false) {
            throw new Exception("Unable to generate secure random bytes for UUID.");
        }

        // Set version to 0100
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);

        // Set bits 6-7 to 10
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
