# Adminer for Windows and Linux

[Adminer](https://www.adminer.org/) with its own PHP, for people who don't have PHP or don't want
to set up a web server: one `adminer.exe` of about 7 MB for Windows, a `.tar.xz` of about 5 MB for
Linux. Start it and Adminer opens in your browser.

## Run on Windows

Download `adminer-<version>.exe` from the [releases](https://github.com/vrana/adminer-desktop/releases)
and double-click it. A console window titled *Adminer* opens and your browser shows
<http://127.0.0.1:23646/>. To stop Adminer, press Enter in the console or close it.

Nothing is installed. Each start unpacks PHP and Adminer to a temporary folder, which is
deleted again when the console closes. Double-clicking the exe while Adminer is already running
just opens the browser again.

The exe is not signed, so Windows may show *Windows protected your PC* at the first start. Click
*More info* and *Run anyway*.

## Run on Linux

Download `adminer-<version>-linux-x86_64.tar.xz` from the
[releases](https://github.com/vrana/adminer-desktop/releases), unpack it and start `adminer` in a
terminal:

```bash
tar -xf adminer-<version>-linux-x86_64.tar.xz
adminer-<version>/adminer
```

Your browser opens <http://127.0.0.1:23646/>. To stop Adminer, press Enter in the terminal. The PHP
inside is statically linked, so it needs no libraries from your distribution.

## Port

The port is fixed so that bookmarks keep working. If another program uses it, the next free one
of the following ten is taken and the console says so. Starting Adminer while it is already
running just opens the browser again.

## Databases

- **MySQL and MariaDB, PostgreSQL, SQLite** work out of the box.
- **MS SQL** works on Windows if Microsoft's ODBC Driver 17 or 18 for SQL Server is installed.
- **Oracle** works on Windows if Oracle Instant Client is installed and its folder is in `PATH`.

Adminer normally refuses an empty password. Here it accepts one, because the server is reachable
only from your own computer, so a local MySQL root without a password or an SQLite file can be
opened directly.

## Security

- The server listens only on `127.0.0.1`, so it cannot be reached from the network.
- It answers only requests addressed to `127.0.0.1` or `localhost`. A web page you visit cannot
  reach it by pointing its own domain name at your computer (DNS rebinding).
- Other users of the same computer can reach the port. On a shared computer, keep in mind that
  SQLite without a password lets them read and write files with your rights while Adminer runs.

## What's inside

- the compiled `adminer.php` of the release, untouched
- the [login-ip](https://github.com/vrana/adminer/blob/main/plugins/login-ip.php) plugin, which
  allows the empty password from the local computer
- on Windows, the official PHP for Windows, trimmed to the extensions Adminer needs, with its
  licenses, the [sqlsrv](https://github.com/microsoft/msphpsql_sqlsrv) and
  [oci8](https://github.com/php/pecl-database-oci8) extensions and the Visual C++ runtime
- on Linux, a static PHP built by [static-php-cli](https://static-php.dev/) with only the
  extensions Adminer needs, with its licenses
- the small `launcher.php` and `www/index.php` around them, both in `src/`

## Build

The static PHP for Linux is built first, on x86_64 Linux (WSL works), into `cache/static-php/`.
It takes a few minutes and installs static-php-cli's build dependencies with sudo:

```bash
linux/build-php.sh
```

Then, on any system:

```bash
php build.php
```

Downloads the latest Adminer release, the latest PHP 8.5 for Windows, the extensions and the tools
(7-Zip's `7zr.exe` and installer stub, [rcedit](https://github.com/electron/rcedit)), caches them
in `cache/` and writes `dist/adminer-<version>.exe` and `dist/adminer-<version>-linux-x86_64.tar.xz`.
The exe is built only on Windows, as the Visual C++ runtime is copied from the system, and the
`.tar.xz` needs the `xz` command. Pass a version to build another Adminer release:

```bash
php build.php 6.1.2
```

## License

Apache License 2.0 or GPL 2, the same as Adminer. The bundled PHP, its libraries and the
extensions keep their own licenses. On Windows they are listed in `php/license.txt` and
`php/readme-redist-bins.txt` inside the exe, on Linux in `php/license/`.
