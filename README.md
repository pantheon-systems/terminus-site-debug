# Terminus Site Debug Plugin

[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/geraldvillorente/terminus-logs/tree/1.x)

Terminus plugin that:
* download site logs from a specific environment of a [Pantheon](https://www.pantheon.io) sites
* parse the logs for debugging purposes

This will also pull logs on an environment with __multiple containers__.

Learn more about Terminus and Terminus Plugins at:
[https://pantheon.io/docs/terminus/plugins/](https://pantheon.io/docs/terminus/plugins/)

## Examples

Download all logs from `dev`.
```
terminus logs:get site_name.dev
```

**Only** download nginx-access.log and nginx-error.log logs.
```
terminus logs:get site_name.dev --nginx-access --nginx-error
```

**Exclude** nginx-access.log and nginx-error.log from download.
```
terminus logs:get site_name.dev --exclude --nginx-access --nginx-error
```

## Parsing Nginx Access Logs

### Search **nginx-access** logs with 301 status code via PHP.
```
terminus logs:parse site_name.env --type=nginx-access --filter="301" --php
```
### Show how many times the IP visited the site.
```
terminus logs:parse site_name.env --type=nginx-access --shell --grouped-by=ip
```
### Top response by HTTP status.
```
terminus logs:parse site_name.env --type=nginx-access --shell --grouped-by=response-code
```
### Top 403 requests
```
terminus logs:parse site_name.env --type=nginx-access --shell --grouped-by=403
```
### Top 404 requests
```
terminus logs:parse site_name.env --type=nginx-access --shell --grouped-by=404
```
### Top 502 requests
```
terminus logs:parse site_name.env --type=nginx-access --shell --grouped-by=502
```

## Parsing Nginx Error Logs

### Search nginx-error.log for access forbidden error.
```
terminus logs:parse site_name.env --type=nginx-error --filter="access forbidden" --shell
```
### Search nginx-error.log for SSL_shutdown error.
```
terminus logs:parse site_name.env --type=nginx-error --filter="SSL_shutdown" --shell
```

## Parsing PHP Error Logs

### Search **php-error** logs with 301 "Uncaught PHP Exception" error.
```
terminus logs:parse site_name.env --type=php-error --filter="Uncaught PHP Exception" --php
```
### Search to all the logs.
```
terminus logs:parse site_name.env --type=all --filter="error" --php
```

### Parsing MySQL Slow Log
```
terminus logs:parse site_name.env --type=mysql --shell
```

## Logs listing

### To list all the log files.
```
terminus logs:list site_name.env
```

## Installation
For help installing, see [Manage Plugins](https://pantheon.io/docs/terminus/plugins/)
```
mkdir -p ~/.terminus/plugins
cd ~/.terminus/plugins
git clone https://github.com/geraldvillorente/terminus-site-debug.git
```
