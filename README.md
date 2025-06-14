This repository provides a **Monolog log formatter optimized for AWS Lambda and CloudWatch**.

Read all about logs [in the Bref documentation](https://bref.sh/docs/environment/logs).

---

Logs will be formatted to combine readability and structured data, making it easy to both read logs and filter them. Here's an example of a log record:

```
INFO   This is a log message   {"message":"This is a log message","level":"INFO"}
```

The JSON object contains the following fields (when applicable):

```json
{
    "message": "Uncaught exception: 'RuntimeException' with message 'Exception message'",
    "level": "ERROR",
    "exception": {
        "class": "RuntimeException",
        "message": "Exception message",
        "code": 0,
        "file": "/var/task/index.php:11",
        "trace": [ ... ]
    },
    "context": {
        "key": "value",
        ...
    },
    "extra": {
        "key": "value",
        ...
    }
}
```

The JSON object is parsed by CloudWatch, allowing you to filter logs in CloudWatch Logs Insights. For example:

```
fields @timestamp, @message
| filter level = 'ERROR'
| filter exception.class = 'RuntimeException'
| sort @timestamp desc
| limit 1000
```

## Installation

> [!IMPORTANT]  
> This package is already installed if you use Bref's [Laravel bridge](https://bref.sh/docs/laravel/getting-started) or [Symfony bridge](https://bref.sh/docs/symfony/getting-started).
> If you do, you don't need to install this package.

```bash
composer require bref/monolog-bridge
```

## Usage

For Laravel applications, set the following environment variable in `serverless.yml`:

```yaml
provider:
    environment:
        LOG_STDERR_FORMATTER: Bref\Monolog\CloudWatchFormatter
```

For Symfony applications, set the Bref Monolog formatter in your `config/packages/prod/monolog.yaml`:

```yaml
monolog:
    handlers:
        file:
            type: stream
            level: info
            formatter: 'Bref\Monolog\CloudWatchFormatter'
```

For other applications, you can set the formatter in your Monolog configuration. For example:

```php
$handler = new Monolog\Handler\StreamHandler('php://stderr', Monolog\Logger::INFO);
$handler->setFormatter(new Bref\Monolog\CloudWatchFormatter);
$logger = new Monolog\Logger('default');
$logger->pushHandler($handler);
```

## Motivation

The goal of this is to improve the overall logs experience with CloudWatch.

The usual format is to log unstructured text. This sucks because:

- we can't filter by log level (e.g. look for errors)
- we can't easily filter and see all logs of a request/invocation via the AWS request ID (e.g. as log metadata)
- some log messages are split across multiple lines
- exception traces especially are split across loads of lines, not easy to navigate in CloudWatch or other line-based viewers

Here's an example:

![Screen-002902](https://github.com/user-attachments/assets/38571928-77e5-41bc-a2ea-f67435ce720b)

A better approach is to switch to JSON-formatted logs:

- we can add metadata to log records (e.g. request ID to filter/see all logs of a request, or filter by log level)
- exception traces are organized as sub-metadata of the exception record, keeping things easier to navigate

But this isn't perfect:

- depending on the tool you use to view the logs, the UX can be very very poor
- it's hard to explore the logs quickly because now all info is nested in a log record, with no hierarchy to preview "just the message" quickly
- Monolog's JSON formatter adds tons of info to the JSON record

Here's an example (Bref Cloud formats the JSON payload FYI, this isn't the case in CloudWatch):

![Screen-002903](https://github.com/user-attachments/assets/6cdf6498-78d2-4a69-b8e5-83dd02856677)

CloudWatch has some great features though:

```
This is the log message {"key":"value"}
```

The log message above will automatically get parsed as text (the first part) and a JSON object attached (the second part).

**That means we can have a simple log message + a structured JSON object**.

On top of that, with CloudWatch:

- we don't need to log the timestamp, CloudWatch automatically timestamps log records
- I don't think we need to log the "channel" name: IMO channels make sense to split web logs from jobs or artisan commands, but in Lambda these are separate functions/logs anyway, so I think we can remove that part too

This is why I want to add this `CloudWatchFormatter` optimized for Bref users.

As you can see in the screenshots below, it is easy to navigate the logs textually. But it's also possible to have a lot more information nested in the log records via the JSON object.

In CloudWatch:

![Screen-002905](https://github.com/user-attachments/assets/721872e6-1c3b-4549-9931-8f597d1f1c7e)

In Bref Cloud:

![Screen-002904](https://github.com/user-attachments/assets/4afa6cfd-1b6f-4d04-8233-f1e0a4901fce)

In CloudWatch Logs Insights, we can see that keys of the JSON objects are correctly detected:

![Screen-002906](https://github.com/user-attachments/assets/6a0c011c-667f-4778-b952-cc036e8e46d3)

That allows us to create advanced queries, for example to search for "ERROR" logs with a specific exception class:

![Screen-002907](https://github.com/user-attachments/assets/8158a402-0875-499d-a693-62114ab7d07a)
