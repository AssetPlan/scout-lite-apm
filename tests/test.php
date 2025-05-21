<?php

require_once 'vendor/autoload.php'; // if needed

use AssetPlan\ScoutLiteAPM\TraceSession;

// Bootstrap with fake values
TraceSession::bootstrap(getenv('SCOUT_APP_NAME'), getenv('SCOUT_KEY'), getenv('SCOUT_SOCKET_PATH'));

TraceSession::startRequest();

TraceSession::instrument('Lifecycle/beforeFilter', function () {
    usleep(100000);
    return 'beforeFilter';
});

// Simulate controller action
$controllerSpan = TraceSession::startController('TestController', 'index');
usleep(10000);
// Simulate SQL
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(10000);
TraceSession::endSql($sqlSpan);
TraceSession::endController($controllerSpan);


$customId = TraceSession::startCustom('Lifecycle/afterFilter');

usleep(200000);

TraceSession::endCustom($customId);



// Finalize
TraceSession::endRequest();

// Dump output buffer
$reflect = new ReflectionClass(TraceSession::class);
$bufferProp = $reflect->getProperty('eventBuffer');
$bufferProp->setAccessible(true);
$buffer = $bufferProp->getValue();

$health = TraceSession::isValid() ? 'Valid' : 'Invalid';

echo "\n--- Trace Session Health: $health ---\n";


echo "\n--- Buffered Events ---\n";
var_dump($buffer);

// Optional: manually flush to socket
//TraceSession::flush();
