<?php

use AssetPlan\ScoutLiteAPM\TraceSession;

// Stands in for a Composer package that runs a query on the app's behalf
function fake_vendor_end_sql($spanId)
{
    TraceSession::endSql($spanId);
}
