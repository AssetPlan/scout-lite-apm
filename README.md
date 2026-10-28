# ScoutLite APM

A lightweight, zero-dependency PHP tracing utility compatible with the [Scout APM Core Agent](https://docs.scoutapm.com/#scout-apm-core-agent). Designed for legacy PHP applications (CakePHP 1.3, Laravel 5.x, custom stacks) running PHP 5.6+.

No Composer bloat, no framework magic. Just trace your code and send to the agent.

---

## ✨ Features

* ✅ Works with PHP **5.6+**
* ✅ Compatible with **Scout APM Core Agent**
* ✅ Flush-safe in environments using `exit()` and `die()`
* ✅ Tracks controller actions, SQL queries, and custom code spans
* ✅ Automatically redacts SQL values
* ✅ Simple API for instrumentation
* ✅ Fully buffered and flushed in a single atomic write
* ✅ Measures request queue time (when available)

---

## 📦 Installation

Via Composer:

```bash
composer require assetplan/scout-lite-apm
```

---

## 🚀 Quick Start

```php
use AssetPlan\ScoutLiteAPM\TraceSession;

// Bootstrap (registers your app + configures socket path)
TraceSession::bootstrap('YourAppName', 'your-agent-key');

// Start the request
TraceSession::startRequest();

// Optionally measure queue time automatically

// Custom lifecycle span (e.g. beforeFilter in CakePHP)
$lifecycle = TraceSession::startCustom('Lifecycle/beforeFilter');
// ...
TraceSession::endCustom($lifecycle);

// Instrument a controller span
$controller = TraceSession::startController('UserController', 'index');
// ...
TraceSession::endController($controller);

// Instrument SQL (automatically redacted)
$sqlSpan = TraceSession::startSql('SELECT * FROM users WHERE id = 123');
// ...
TraceSession::endSql($sqlSpan);

// Finish the request
TraceSession::endRequest();

// Flush (optional — you can also register a shutdown handler)
TraceSession::flush();
```

---

## 🔐 Requirements

* PHP 5.6 or greater
* [Scout APM Core Agent](https://docs.scoutapm.com/#scout-apm-core-agent) running and listening via UNIX or TCP socket

---

## ⚙️ Configuration

By default, the Core Agent is expected at `tcp://127.0.0.1:6590`.
To override the socket path or API version:

```php
TraceSession::bootstrap('YourApp', 'your-key', '/tmp/scout-agent.sock');
// or
TraceSession::register('YourApp', 'your-key', '/tmp/scout-agent.sock', '1.0');
```

---

## 🐢 Slow SQL backtraces

When a SQL span lasts **500 ms or more**, `endSql()` attaches a `stack` tag with up to 10 application frames, so Scout shows the file and line that issued the query. Queries under the threshold cost nothing extra: `debug_backtrace()` is only called above it.

The tag uses the same format as Scout's official PHP agent: a list of `{"file": "/absolute/path.php", "line": 42, "function": "Class->method"}`.

Change the threshold per app, in milliseconds (`0` or negative disables it):

```php
TraceSession::setSqlBacktraceThreshold(250);
```

or with an environment variable, read by `bootstrap()`:

```bash
SCOUT_SQL_BACKTRACE_MS=250
```

Frames from the library itself are always dropped. Frames whose file path contains any of these fragments are dropped too, so the trace points at your application code. The default is `['/cake/', '/vendor/', '/composer-modules/']`. Other frameworks can set their own:

```php
// e.g. Laravel 5.x
TraceSession::setSqlBacktraceExcludePaths(['/vendor/']);
```

---

## 💡 Tips

* Calling `flush()` multiple times is safe — only one send will occur.
* If any span is left open, or `FinishRequest` is missing, flush will be aborted.
* Custom instrumentation can be added using `startCustom()` / `endCustom()` or `instrument()`.
* Queue time will be included if headers like `X-Request-Start` are available.
* You can inspect internals via:

  * `TraceSession::getOpenSpans()`
  * `TraceSession::getEventBuffer()`
  * `TraceSession::getRequestId()`

---

## 🤝 License

MIT © AssetPlan

---

## 🧠 Authors

Crafted by humans who deal with legacy PHP and still want great observability.

* Cristian Fuentes ([@cfuentessalgado](https://github.com/cfuentessalgado))
