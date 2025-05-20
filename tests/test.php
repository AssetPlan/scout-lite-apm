<?php

require_once 'vendor/autoload.php'; // if needed

use AssetPlan\ScoutLiteAPM\TraceSession;

// Bootstrap with fake values
TraceSession::bootstrap('TestApp', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

TraceSession::startRequest();

// Simulate controller action
$controllerSpan = TraceSession::startController('TestController', 'index');
usleep(1000);
TraceSession::endController($controllerSpan);

// Simulate SQL
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(1000);
TraceSession::endSql($sqlSpan);

// Finalize
TraceSession::endRequest();

// Dump output buffer
$reflect = new ReflectionClass(TraceSession::class);
$bufferProp = $reflect->getProperty('eventBuffer');
$bufferProp->setAccessible(true);
$buffer = $bufferProp->getValue();

echo "\n--- Buffered Events ---\n";
var_dump($buffer);

// Optional: manually flush to socket
// TraceSession::flush();
