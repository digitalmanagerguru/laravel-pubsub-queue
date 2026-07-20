# Laravel PubSub Queue
[![GitHub tag](https://img.shields.io/github/tag/digitalmanagerguru/laravel-pubsub-queue?include_prereleases=&sort=semver&color=blue)](https://github.com/digitalmanagerguru/laravel-pubsub-queue/releases/)
[![License](https://img.shields.io/badge/License-MIT-blue)](#license)
[![issues - laravel-pubsub-queue](https://img.shields.io/github/issues/digitalmanagerguru/laravel-pubsub-queue)](https://github.com/digitalmanagerguru/laravel-pubsub-queue/issues)

This package is a Laravel queue driver that uses the [Google Cloud Pub/Sub](https://github.com/googleapis/google-cloud-php-pubsub) service.

## Installation

Install with [Composer](https://getcomposer.org):

```bash
composer require digitalmanagerguru/laravel-pubsub-queue
```

If you disabled package discovery, register the provider manually in the `providers` array of `config/app.php`:

```php
Digitalmanagerguru\PubSubQueue\PubSubQueueServiceProvider::class,
```

## Configuration

Add a `pubsub` connection to `config/queue.php`. Any option accepted by the underlying
[Google Cloud Pub/Sub client](https://cloud.google.com/php/docs/reference/cloud-pubsub/latest/PubSubClient)
can be passed here (use `snake_case` keys — they are converted to `camelCase` before reaching the client).

```php
'pubsub' => [
    'driver' => 'pubsub',
    'queue' => env('PUBSUB_QUEUE', 'default'),
    'queue_prefix' => env('PUBSUB_QUEUE_PREFIX', ''),
    'project_id' => env('PUBSUB_PROJECT_ID', 'your-project-id'),
    'keyFilePath' => env('PUBSUB_KEY_FILE'),           // path to the service-account JSON (optional; ADC used if omitted)
    'retrySettings' => [
        'maxRetries' => 3,
    ],
    'request_timeout' => env('PUBSUB_REQUEST_TIMEOUT', 60),
    'subscriber' => env('PUBSUB_SUBSCRIBER', 'subscriber-name'),
    'create_topics' => env('PUBSUB_CREATE_TOPICS', true),
    'create_subscriptions' => env('PUBSUB_CREATE_SUBSCRIPTIONS', true),
    'return_immediately' => env('PUBSUB_RETURN_IMMEDIATELY', false),
    'max_messages' => env('PUBSUB_MAX_MESSAGES', 1),
],
```

### Options

| Key | Default | Description |
|-----|---------|-------------|
| `queue` | `default` | Default queue (Pub/Sub **topic**) name. |
| `queue_prefix` | `''` | Prefix prepended to every topic/queue name (e.g. `myapp-`). |
| `project_id` | — | Google Cloud project id. |
| `keyFilePath` | — | Path to the service-account JSON. If omitted, Application Default Credentials are used. |
| `subscriber` | `subscriber` | The **subscription** name used to consume. One worker deployment = one subscription. |
| `create_topics` | `true` | Auto-create the topic if missing. Set `false` in production to avoid admin-operation limits. |
| `create_subscriptions` | `true` | Auto-create the subscription if missing. Set `false` in production. |
| `retrySettings` | `maxRetries: 3` | Passed through to the Pub/Sub client. |
| `request_timeout` | `60` | Per-RPC timeout (seconds). Also bounds how long a long-poll `pull` blocks — see [Long-polling](#long-polling-return_immediately). |
| `return_immediately` | `false` | Pull mode — see [Long-polling](#long-polling-return_immediately). |
| `max_messages` | `1` | Messages fetched per pull — see [Batching](#batching-max_messages). |

## How it works

### Delivery semantics — at-most-once

`pop()` **acknowledges each message as soon as it is pulled** (before the job runs). This gives
**at-most-once** delivery: if a worker crashes mid-processing, the in-flight (and any buffered)
messages are *not* redelivered. This trades the (rare) lost message for never double-processing —
suitable for idempotent / externally-deduplicated work (e.g. analytics pixels, webhooks with their
own dedup). If you need at-least-once, do not use this driver as-is.

### Batching (`max_messages`)

Each empty-buffer `pop()` issues **one** `pull` for up to `max_messages` messages, acknowledges the
whole batch in a single `acknowledgeBatch` call, buffers them in memory, and serves them to the
worker one at a time. Subsequent `pop()` calls drain the buffer without hitting the API.

- `max_messages = 1` (default) — one message per pull; behaviour identical to fetching singly.
- `max_messages > 1` — fewer pull/ack round-trips per message. This is the recommended setting for
  high-throughput, short-lived jobs: the cheaper, batched acknowledgements land well within the
  subscription's ack deadline, which **avoids ack-deadline expiry and the redelivery it causes**.

Because of at-most-once semantics, a crash can lose up to `max_messages` buffered messages, so keep
the value modest (e.g. `5`–`20`).

### Long-polling (`return_immediately`)

- `false` (default, **recommended**) — the `pull` **long-polls**: it blocks until at least one
  message is available (or `request_timeout` elapses) and returns backlog messages promptly.
- `true` — the `pull` returns immediately; it may return an empty response *even when the backlog is
  non-empty*, leaving messages waiting and inflating latency. Avoid for worker consumption.

When long-polling, an idle `pop()` blocks for up to `request_timeout` seconds. Keep `request_timeout`
**below** your worker's shutdown grace period (e.g. Kubernetes `terminationGracePeriodSeconds`) so a
`SIGTERM` during a deploy is honoured promptly instead of being `SIGKILL`ed.

### Delayed jobs

Jobs dispatched with a delay (`later()` / released with a backoff) carry a future `available_at`
attribute. Such messages are pulled but **not** acknowledged until they are due, so Pub/Sub
redelivers them at the right time. Delayed messages are excluded from a batch (only currently-due
messages are acknowledged and buffered).

### Message ordering

Ordering keys set on a job (`$job->orderingKey`) are forwarded on publish. Enabling ordered delivery
also requires enabling message ordering on the subscription. Note that ordering does not change the
at-least-once nature of Pub/Sub and can increase head-of-line latency.

## Avoiding administrator-operation limits

In production, set `create_topics` and `create_subscriptions` to `false` (create the topic and
subscription ahead of time via IaC / `gcloud`) to avoid hitting Pub/Sub admin-operation quotas on
every boot.

## Testing

```bash
vendor/bin/phpunit
```

## License

This project is licensed under the terms of the MIT license. See [License File](LICENSE) for more information.
