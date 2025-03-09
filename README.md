# Chief Tools CLI

An easy-to-use command line interface for [Chief Tools](https://chief.app?ref=ghcli).

## Installation

You can download the PHAR file directly from the GitHub releases page or you can install using [Composer](https://getcomposer.org/):

```bash
composer global require "chieftools/cli:*"
```

## Usage

You can use the CLI to interact with various Chief Tools. To see the available commands, run:

```bash
chief
```

Most commands require authentication. You can authenticate using the `chief auth login` command. This will open a browser window where you can log in to your Chief Tools account.

```bash
chief auth login
```

You can see the current authentication status using the `chief auth status` command.

```bash
chief auth status
```

## Feature requests

You can submit feature requests and bug reports on the [roadmap](https://roadmap.chief.tools/projects/cli?ref=ghcli).

## Credits

- [Stan Menten](https://stanmenten.dev) (Original author)
- [Alex Bouma](https://github.com/stayallive) (Maintainer)
- [Chief Tools](https://chief.app?ref=ghcli)
- [All Contributors](../../contributors)

## Security Vulnerabilities

If you discover a security vulnerability, please send an e-mail to us at `hello@chief.app`. All security vulnerabilities will be swiftly addressed.

## License

The Chief Tools CLI is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).
