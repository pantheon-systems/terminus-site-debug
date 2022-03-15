# Logs Parser

[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained)

[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/geraldvillorente/terminus-logs/tree/1.x)

A Terminus plugin that:
* download site logs from a specific environment of a [Pantheon](https://www.pantheon.io) sites
* parse the logs for debugging purposes

This will also pull logs on an environment with __multiple containers__.

Learn more about Terminus and Terminus Plugins at:
[https://pantheon.io/docs/terminus/plugins/](https://pantheon.io/docs/terminus/plugins/)

## Examples

Download all logs from `dev`.
```
terminus logs:get <site>.<env>
```

**Only** download nginx-access.log and nginx-error.log logs.
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
This error means that the site has no enough PHP workers. Consider upgrading to a higher plan to add more appservers.
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

### Search for the latest entries.
```
terminus logs:parse:php-slow <site>.<env> --grouped-by=latest 
```
### Top functions by number of times they called.
```
terminus logs:parse:php-slow <site>.<env> --grouped-by=function
```
### Slow requests grouped by minute
```
terminus logs:parse:php-slow <site>.<env> --grouped-by=minute
```

## Parsing MySQL Slow Log

### Display everything.
```
terminus logs:parse:mysql-slow <site>.<env> 
```
### Count of queries based on their time of execution. 
```
terminus logs:parse:mysql-slow <site>.<env> --grouped-by=time
```
### Display only the first N queries in the output. Sort output by count i.e. number of times query found in mysqld-slow-query.log.
This queries might be a good option for caching the result.
```
terminus logs:parse:mysql-slow <site>.<env> --grouped-by=query-count 
```
### Display only the first N queries in the output. 
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
```
mkdir -p ~/.terminus/plugins
cd ~/.terminus/plugins
git clone https://github.com/pantheon-systems/terminus-site-debug.git
```

To install this in Terminus 3, run this command:
```
terminus self:plugin:install pantheon-systems/terminus-site-debug
```

## Credits 
* To https://github.com/jfussion for the idea and initial codebase.
