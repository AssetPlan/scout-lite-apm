<?php

namespace AssetPlan\ScoutLiteAPM\Support;

class Timestamp
{
    public static function formatNow()
    {
        date_default_timezone_set('UTC');
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        $dt = $dt->setTimezone(new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s.u\Z');
    }
}
