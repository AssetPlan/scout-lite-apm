<?php

use AssetPlan\ScoutLiteAPM\TraceSession;

// Stands in for a Composer package that runs a query on the app's behalf
function fake_vendor_end_sql($spanId)
{
    TraceSession::endSql($spanId);
}

// Stands in for a framework that recurses deeply (e.g. CakePHP association queries) before ending the span
function fake_vendor_deep_end_sql($spanId, $depth)
{
    if ($depth > 0) {
        fake_vendor_deep_end_sql($spanId, $depth - 1);
        return;
    }
    TraceSession::endSql($spanId);
}
