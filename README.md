# Terminus Get Logs Plugin

[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-secrets-plugin/tree/1.x)

Terminus Plugin that allows to download all logs from a specific environment of a [Pantheon](https://www.pantheon.io) sites.

This will also pull logs on an environment with __multiple containers__.

Learn more about Terminus and Terminus Plugins at:
[https://pantheon.io/docs/terminus/plugins/](https://pantheon.io/docs/terminus/plugins/)

## Configuration

This plugin requires no configuration to use.

## Examples

Download all logs from `dev`.
```
terminus logs:get my_site.dev
```

**Only** download nginx-access.log and nginx-error.log logs.
```
terminus logs:get my_site.dev --nginx-access --nginx-error
```

**Exclude** nginx-access.log and nginx-error.log from download.
```
terminus logs:get my_site.dev --exclude --nginx-access --nginx-error
```

## Parsing Logs

Search **nginx-access** logs with 301 status code.
```
terminus logs:parse mysite.env nginx-access "301"
```

Search **php-error** logs with 301 "Uncaught PHP Exception" error.
```
terminus logs:parse mysite.env php-error "Uncaught PHP Exception"
```

## Logs listing
To list all the log files.
```
terminus logs:list
```

## Installation
For help installing, see [Manage Plugins](https://pantheon.io/docs/terminus/plugins/)
```
mkdir -p ~/.terminus/plugins
cd ~/.terminus/plugins
git clone https://github.com/geraldvillorente/terminus-logs.git
```

## Configuration
To configure the logs directory. This is one time only.
```
terminus logs:set:dir /path/to/logs/directory
```
Or
```
terminus logsd /path/to/logs/directory
```
