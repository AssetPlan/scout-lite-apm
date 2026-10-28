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

echo "--- Running slow SQL backtrace tests ---\n";

require_once __DIR__ . '/fixtures/vendor/fake/helper.php';

function report($name, $passed, $buffer = null)
{
    echo $name . ': ' . ($passed ? 'PASSED' : 'FAILED') . "\n";
    if (!$passed && $buffer !== null) {
        var_dump($buffer);
    }
}

function startTestRequest($thresholdMs)
{
    TraceSession::resetSession();
    TraceSession::bootstrap('TestApp', 'TestKey', 'tcp://127.0.0.1:6590', '1.0', 'testhost');
    TraceSession::setSqlBacktraceThreshold($thresholdMs);
    TraceSession::startRequest();
}

function stackTagsFor($buffer, $spanId)
{
    $tags = [];
    foreach ($buffer as $index => $eventWrapper) {
        if (isset($eventWrapper['TagSpan']) &&
            $eventWrapper['TagSpan']['span_id'] === $spanId &&
            $eventWrapper['TagSpan']['tag'] === 'stack') {
            $tags[$index] = $eventWrapper['TagSpan'];
        }
    }
    return $tags;
}

function stopIndexFor($buffer, $spanId)
{
    foreach ($buffer as $index => $eventWrapper) {
        if (isset($eventWrapper['StopSpan']) && $eventWrapper['StopSpan']['span_id'] === $spanId) {
            return $index;
        }
    }
    return null;
}

// Test Case 3: slow query gets one stack tag before its StopSpan
startTestRequest(1);
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(5000);
TraceSession::endSql($sqlSpan);
$eventBuffer = $eventBufferProp->getValue();
$tags = stackTagsFor($eventBuffer, $sqlSpan);
$stopIndex = stopIndexFor($eventBuffer, $sqlSpan);
$testPassed = count($tags) === 1 && $stopIndex !== null && key($tags) < $stopIndex;
if ($testPassed) {
    $frames = current($tags)['value'];
    $testPassed = is_array($frames) && count($frames) > 0 && count($frames) <= 10;
    foreach ((array) $frames as $frame) {
        if (!isset($frame['file'], $frame['line'], $frame['function']) || count($frame) !== 3) {
            $testPassed = false;
        }
        if (strpos($frame['file'], dirname(__DIR__) . '/src/') === 0) {
            $testPassed = false;
        }
    }
    $testPassed = $testPassed && $frames[0]['file'] === __FILE__ && $frames[0]['function'] === 'AssetPlan\\ScoutLiteAPM\\TraceSession::endSql';
}
report('Test Case 3 (slow SQL has stack tag)', $testPassed, $eventBuffer);

// Test Case 4: fast query has no stack tag
startTestRequest(10000);
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(5000);
TraceSession::endSql($sqlSpan);
$eventBuffer = $eventBufferProp->getValue();
report('Test Case 4 (fast SQL has no stack tag)', count(stackTagsFor($eventBuffer, $sqlSpan)) === 0 && stopIndexFor($eventBuffer, $sqlSpan) !== null, $eventBuffer);

// Test Case 5: threshold 0 disables backtraces
startTestRequest(0);
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(5000);
TraceSession::endSql($sqlSpan);
$eventBuffer = $eventBufferProp->getValue();
report('Test Case 5 (threshold 0 disables stack tag)', count(stackTagsFor($eventBuffer, $sqlSpan)) === 0, $eventBuffer);

// Test Case 6: frames from a /vendor/ path are filtered out
startTestRequest(1);
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(5000);
fake_vendor_end_sql($sqlSpan);
$eventBuffer = $eventBufferProp->getValue();
$tags = stackTagsFor($eventBuffer, $sqlSpan);
$testPassed = count($tags) === 1;
foreach ($testPassed ? current($tags)['value'] : [] as $frame) {
    if (strpos($frame['file'], '/vendor/') !== false) {
        $testPassed = false;
    }
}
$testPassed = $testPassed && current($tags)['value'][0]['function'] === 'fake_vendor_end_sql';
report('Test Case 6 (vendor frames filtered)', $testPassed, $eventBuffer);

// Test Case 7: SCOUT_SQL_BACKTRACE_MS is read at bootstrap
putenv('SCOUT_SQL_BACKTRACE_MS=1');
TraceSession::setSqlBacktraceThreshold(10000);
TraceSession::resetSession();
TraceSession::bootstrap('TestApp', 'TestKey', 'tcp://127.0.0.1:6590', '1.0', 'testhost');
putenv('SCOUT_SQL_BACKTRACE_MS');
TraceSession::startRequest();
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(5000);
TraceSession::endSql($sqlSpan);
$eventBuffer = $eventBufferProp->getValue();
report('Test Case 7 (SCOUT_SQL_BACKTRACE_MS read at bootstrap)', count(stackTagsFor($eventBuffer, $sqlSpan)) === 1, $eventBuffer);

// Test Case 8: open-span tracking still works with timed SQL spans
startTestRequest(1);
$controllerSpan = TraceSession::startController('TestController', 'index');
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
$openSpans = TraceSession::getOpenSpans();
$testPassed = count($openSpans) === 2 && isset($openSpans[$controllerSpan], $openSpans[$sqlSpan]);
TraceSession::endSql($sqlSpan);
$testPassed = $testPassed && count(TraceSession::getOpenSpans()) === 1 && !TraceSession::isValid();
TraceSession::endController($controllerSpan);
TraceSession::endRequest();
$testPassed = $testPassed && count(TraceSession::getOpenSpans()) === 0 && TraceSession::isValid();
report('Test Case 8 (open span tracking)', $testPassed, TraceSession::getOpenSpans());

// Test Case 9: not bootstrapped, startSql/endSql are silent no-ops
TraceSession::resetSession();
TraceSession::setSqlBacktraceThreshold(1);
$warnings = [];
set_error_handler(function ($errno, $errstr) use (&$warnings) {
    $warnings[] = $errstr;
    return true;
});
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 42');
usleep(5000);
TraceSession::endSql($sqlSpan);
restore_error_handler();
$eventBuffer = $eventBufferProp->getValue();
report('Test Case 9 (not bootstrapped is a no-op)', $sqlSpan === null && empty($eventBuffer) && empty($warnings), $eventBuffer);

TraceSession::setSqlBacktraceThreshold(500);
TraceSession::resetSession();

echo "--- End of slow SQL backtrace tests ---\n\n";


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
