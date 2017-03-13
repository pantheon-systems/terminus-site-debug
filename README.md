# Terminus Rsync Plugin

[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-secrets-plugin/tree/1.x)

Terminus Plugin that allows to download all logs from a specific environment of a [Pantheon](https://www.pantheon.io) sites.
This will also pull logs on an environment with multiple containers.

Learn more about Terminus and Terminus Plugins at:
[https://pantheon.io/docs/terminus/plugins/](https://pantheon.io/docs/terminus/plugins/)

## Configuration

This plugin requires no configuration to use.

## Examples

Download all logs from `dev`.
```
terminus get-logs my_site.dev
```

**Only** download nginx-access.log and nginx-error.log logs.
```
terminus get-logs my_site.dev --nginx-access --nginx-error
```

**Exclude** nginx-access.log and nginx-error.log from download.
```
terminus get-logs my_site.dev --exclude --nginx-access --nginx-error
```

## Installation
For help installing, see [Manage Plugins](https://pantheon.io/docs/terminus/plugins/)
```
mkdir -p ~/.terminus/plugins
cd ~/.terminus/plugins
git clone https://github.com/jfussion/terminus-get-logs.git
```

## Help
Use `terminus help <rsync>` to get help on this command.
