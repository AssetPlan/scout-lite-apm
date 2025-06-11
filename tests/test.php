<?php

require_once 'vendor/autoload.php'; // if needed

use AssetPlan\ScoutLiteAPM\TraceSession;

echo "--- Running revision_sha tests ---\n";

// Test Case 1: SCOUT_REVISION_SHA is set
putenv('SCOUT_REVISION_SHA=test_revision_sha_123');
TraceSession::resetSession(); // Reset session before test
TraceSession::register('TestApp', 'TestKey', 'tcp://127.0.0.1:6590', '1.0', 'testhost');

$reflectClass = new ReflectionClass(TraceSession::class);
$eventBufferProp = $reflectClass->getProperty('eventBuffer');
$eventBufferProp->setAccessible(true);
$eventBuffer = $eventBufferProp->getValue();

$testPassed = false;
$applicationEvent = null;
foreach ($eventBuffer as $eventWrapper) {
    if (isset($eventWrapper['ApplicationEvent'])) {
        $applicationEvent = $eventWrapper['ApplicationEvent'];
        break;
    }
}

if ($applicationEvent !== null) {
    if ($applicationEvent['event_type'] === 'scout.metadata' &&
        $applicationEvent['source'] === 'php' &&
        is_array($applicationEvent['event_value']) &&
        $applicationEvent['event_value']['application_name'] === 'TestApp' &&
        !empty($applicationEvent['event_value']['hostname']) && // or specific value like 'testhost'
        isset($applicationEvent['event_value']['git_sha']) &&
        $applicationEvent['event_value']['git_sha'] === 'test_revision_sha_123') {
        $testPassed = true;
    }
}

if ($testPassed) {
    echo "Test Case 1 (SCOUT_REVISION_SHA set): PASSED\n";
} else {
    echo "Test Case 1 (SCOUT_REVISION_SHA set): FAILED\n";
    var_dump($eventBuffer); // Dump buffer on failure
}

// Test Case 2: SCOUT_REVISION_SHA is not set
putenv('SCOUT_REVISION_SHA'); // Unset the environment variable
TraceSession::resetSession(); // Reset session before test
TraceSession::register('TestAppNoSha', 'TestKeyNoSha', 'tcp://127.0.0.1:6590', '1.0', 'testhostNoSha');
$eventBuffer = $eventBufferProp->getValue();

$testPassed = false;
$applicationEvent = null;
foreach ($eventBuffer as $eventWrapper) {
    if (isset($eventWrapper['ApplicationEvent'])) {
        $applicationEvent = $eventWrapper['ApplicationEvent'];
        break;
    }
}

if ($applicationEvent !== null) {
    if (is_array($applicationEvent['event_value']) &&
        !isset($applicationEvent['event_value']['git_sha']) &&
        $applicationEvent['event_value']['application_name'] === 'TestAppNoSha' &&
        $applicationEvent['event_value']['hostname'] === 'testhostNoSha') {
        $testPassed = true;
    }
}

if ($testPassed) {
    echo "Test Case 2 (SCOUT_REVISION_SHA not set): PASSED\n";
} else {
    echo "Test Case 2 (SCOUT_REVISION_SHA not set): FAILED\n";
    var_dump($eventBuffer); // Dump buffer on failure
}

echo "--- End of revision_sha tests ---\n\n";


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
