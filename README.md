# Terminus Logs Parser

[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)

[![Terminus v1-4 Compatible](https://img.shields.io/badge/terminus-v4.x-green.svg)](https://github.com/geraldvillorente/terminus-logs/tree/4.x)

A Terminus plugin that allows a [Pantheon](https://www.pantheon.io) user to:

* download nginx, PHP & mysql logs (including error, access & slow logs) for __all containers__ for a specific site/environment, and
* parse the logs using filtering and grouping options.

> ⭐ This plugin downloads logs for __all containers__ for an environment. When using an SFTP connection, you will only have access to the logs for a single application instance (i.e. the /logs directory).

Learn more about Terminus and Terminus Plugins at:
[https://pantheon.io/docs/terminus/plugins/](https://pantheon.io/docs/terminus/plugins/)

## How to use

First make sure `terminus-site-debug` plugin is installed on your machine. Installation instructions are below.

The plugin adds several commands to Terminus.

The `logs:get` command requires `site` and `environment` (or `env`) arugments in the format `site.env`. For example:

```
terminus logs:get <site>.<env>
```

The `logs:parse` command set allows you to filter and group types of log entries.
```
terminus logs:parse <site>.<env>

terminus logs:parse:nginx-access <site>.<env>
terminus logs:parse:nginx-error <site>.<env>

terminus logs:parse:php-error <site>.<env>
terminus logs:parse:php-slow <site>.<env>

terminus logs:parse:mysql-slow <site>.<env> 
```

Each command accepts multiple arguments. See the examples below and modify them for your own usage.

By default, logs are downloaded to the `~/.terminus/site-logs/` directory.

## Examples

Basic usage to download all logs from an environment.
```
terminus logs:get <site>.<env>
```

Download **only** nginx-access.log and nginx-error.log logs.
```
terminus logs:get <site>.<env> --nginx-access --nginx-error
```

**Exclude** nginx-access.log and nginx-error.log from download.
```
terminus logs:get <site>.<env> --exclude --nginx-access --nginx-error
```

## Parsing Nginx Access Logs

### Search **nginx-access** logs with 301 status code via PHP.
```
terminus logs:parse:nginx-access <site>.<env> --filter="301" --php
```
### Show how many times the IP visited the site.
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=ip
```
### Top response by HTTP status.
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=response-code
```
### Top 403 requests
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=403
```
### Top 404 requests
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=404
```
### Top PHP 404 requests.
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=php-404
```
### Top PHP 404 requests in full details.
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=php-404-detailed
```
### Top 502 requests
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=502
```
### Top IPs accessing 502. 
To get 502 URIs run this command first: `terminus logs:parse:nginx-access site_name.env --grouped-by=502`
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=ip-accessing-502 --uri={SITE_URI}
```
### Count the request that hits the appserver per second.
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=request-per-second
```
### Top request methods.
```
terminus logs:parse:nginx-access <site>.<env> --grouped-by=request-method --code=[200|403|404|502]
```

## Parsing Nginx Error Logs

### Search nginx-error.log for access forbidden error.
```
terminus logs:parse:nginx-error <site>.<env> --grouped-by="access forbidden" 
```
### Search nginx-error.log for SSL_shutdown error.
```
terminus logs:parse:nginx-error <site>.<env> --grouped-by="SSL_shutdown" 
```
### Search nginx-error.log for "worker_connections" error. 
This error suggests that the site does not have enough PHP workers, or they're being overloaded. See our documentation on [Overloaded Workers](https://docs.pantheon.io/guides/errors-and-server-responses/overloaded-workers).
```
terminus logs:parse:nginx-error <site>.<env> --grouped-by="worker_connections" 
```
### To get the latest entries. 
You can adjust the results by passing a numeric value to `--filter` which has a default value of `10`.
```
terminus logs:parse:nginx-error <site>.<env>
```

## Parsing PHP Error Logs

### Search for the latest entries.
```
terminus logs:parse:php-error <site>.<env> --grouped-by=latest
```
### Search **php-error** logs with 301 "Uncaught PHP Exception" error.
```
terminus logs:parse <site>.<env> --type=php-error --filter="Uncaught PHP Exception" --php
```
### Search to all the logs.
```
terminus logs:parse <site>.<env> --type=all --filter="error" --php
```

## Parsing PHP Slow Logs

### Output for the latest entries.
```
terminus logs:parse:php-slow <site>.<env> --grouped-by=latest 
```
### Output function names and number of times each function appears.
```
terminus logs:parse:php-slow <site>.<env> --grouped-by=function
```
### Slow requests grouped by minute
```
terminus logs:parse:php-slow <site>.<env> --grouped-by=minute
```

## Parsing MySQL Slow Log

‼️ This command requires the `mysqldumpslow` tool, which may not be available on all systems.

### Display everything.
```
terminus logs:parse:mysql-slow <site>.<env> 
```
### Output number of queries based on execution time.
```
terminus logs:parse:mysql-slow <site>.<env> --grouped-by=time
```
### Output only the first **__n__** queries. Sort output by count (i.e. number of times query found in mysqld-slow-query.log).
This command is helpful when searching for queries which could be optimized or cached.
```
terminus logs:parse:mysql-slow <site>.<env> --grouped-by=query-count 
```
### Display only the first **__n__** queries in the output. 
Top queries which returned maximum rows.
```
terminus logs:parse:mysql-slow <site>.<env> --grouped-by=average-rows-sent
```

## Logs listing

### To list all the log files.
```
terminus logs:list <site>.<env>
```

## Support
This plugin is not working on Windows environment. You may want to Dockerized your Terminus to use this kind of plugin that uses *nix* commands to parse the logs.

## Installation
For help installing, see [Manage Plugins](https://pantheon.io/docs/terminus/plugins/)

For recent versions of Terminus:
```
terminus self:plugin:install pantheon-systems/terminus-site-debug
```

For earlier versions (v2 and below):
```
mkdir -p ~/.terminus/plugins
cd ~/.terminus/plugins
git clone https://github.com/pantheon-systems/terminus-site-debug.git
```

## Credits 
* To https://github.com/jfussion for the idea and initial codebase.
