# Adminer for Windows, Linux and macOS

[Adminer](https://www.adminer.org/) with its own PHP, for people who don't have PHP or don't want
to set up a web server: one `adminer.exe` of about 7 MB for 64-bit Windows, a `.tar.xz` of about 5 MB for
Linux, an `Adminer.app` in a `.zip` of about 7 MB for macOS. Start it and Adminer opens in a window
of your browser.

## Run on Windows

Download `adminer-<version>-x64.exe` from the [releases](https://github.com/vrana/adminer-desktop/releases)
and double-click it. Adminer opens in a window of its own without tabs and the address bar, shown
by your default browser if it is Chrome, Edge, Brave or Chromium, otherwise by the first of them
you have; Windows comes with Edge. Closing the window stops Adminer. Without them, Adminer opens in your default browser at
<http://127.0.0.1:23646/> and a message box keeps it running until you click OK.

Nothing is installed. Each start unpacks PHP and Adminer to a temporary folder, which is
deleted again when Adminer stops. Double-clicking the exe while Adminer is already running
opens another window.

The exe is not signed yet (see the [code signing policy](#code-signing-policy)), so Windows may
show *Windows protected your PC* at the first start. Click *More info* and *Run anyway*.

## Run on Linux

Download `adminer-<version>-linux-x86_64.tar.xz` from the
[releases](https://github.com/vrana/adminer-desktop/releases), unpack it and start `adminer`. Started
from a file manager, it opens a terminal window. In a terminal:

```bash
tar -xf adminer-<version>-linux-x86_64.tar.xz
adminer-<version>/adminer
```

Adminer opens at <http://127.0.0.1:23646/> in a window without tabs and the address bar, shown by
your default browser if it is Chrome, Edge, Brave or Chromium, otherwise by the first of them you
have. Without them, it opens in your default browser. To stop Adminer, press Enter in the terminal.
The PHP inside is statically linked, so it needs no libraries from your distribution.

## Run on macOS

Download `adminer-<version>-macos-arm64.zip` from the
[releases](https://github.com/vrana/adminer-desktop/releases) and open it, which unpacks
`Adminer.app`. Open the app: a Terminal window runs Adminer, which opens at
<http://127.0.0.1:23646/> in a window without tabs and the address bar, shown by your default
browser if it is Chrome, Edge, Brave or Chromium, otherwise by the first of them you have. Without
them, it opens in your default browser. To stop Adminer, press Enter in the Terminal window.
It needs a Mac with Apple Silicon and macOS 12 or newer.

The app is not signed by a registered Apple developer, so macOS refuses to open it at first. Go to
*System Settings › Privacy & Security* and click *Open Anyway* next to the message about Adminer.

If macOS says that the app is damaged, remove the mark macOS puts on downloaded files instead:

```bash
xattr -dr com.apple.quarantine Adminer.app
```

## Port

The port is fixed so that bookmarks keep working. If another program uses it, the next free one
of the following ten is taken; on Linux and macOS, the terminal says so. Starting Adminer while it
is already running just opens the browser again, or another window on Windows.

## Databases

- **MySQL and MariaDB, PostgreSQL, SQLite** work out of the box.
- **MS SQL** works on Windows if Microsoft's ODBC Driver 17 or 18 for SQL Server is installed.
- **Oracle** works on Windows if Oracle Instant Client is installed and its folder is in `PATH`.
- **Elasticsearch, ClickHouse, Redis** work through Adminer's plugin drivers, also with `https://`
  servers.

Adminer normally refuses an empty password. Here it accepts one, because the server is reachable
only from your own computer, so a local MySQL root without a password or an SQLite file can be
opened directly.

## Security

- The server listens only on `127.0.0.1`, so it cannot be reached from the network.
- It answers only requests addressed to `127.0.0.1` or `localhost`, apart from the CSS files of the
  designs. A web page you visit cannot reach it by pointing its own domain name at your computer
  (DNS rebinding).
- Other users of the same computer can reach the port. On a shared computer, keep in mind that
  SQLite without a password lets them read and write files with your rights while Adminer runs.

## What's inside

- the compiled `adminer.php` of the release, untouched
- the [login-ip](https://github.com/vrana/adminer/blob/main/plugins/login-ip.php) plugin, which
  allows the empty password from the local computer
- the optional [plugins](https://www.adminer.org/plugins/), disabled; enable them by the
  Configuration link on the server page
- the [designs](https://www.adminer.org/#extras) from Adminer's repository; choose one on the same
  Configuration page
- the plugin [drivers](https://www.adminer.org/plugins/#use) for ClickHouse, Elasticsearch and
  Redis, compiled from the release's sources into the same files as on adminer.org; the others
  need PHP extensions which are not bundled
- on Windows, the official PHP for Windows, trimmed to the extensions Adminer needs, with its
  licenses, the [sqlsrv](https://github.com/microsoft/msphpsql_sqlsrv) and
  [oci8](https://github.com/php/pecl-database-oci8) extensions and the Visual C++ runtime
- on Linux and macOS, a static PHP built by [static-php-cli](https://static-php.dev/) with only the
  extensions Adminer needs, with its licenses
- the small `launcher.php` and `www/index.php` around them, both in `src/`

## Build

The static PHP for Linux and macOS is built first, into `cache/static-php/`. The Linux one on
x86_64 Linux (WSL works), which takes a few minutes and installs static-php-cli's build
dependencies with sudo:

```bash
./build-php.sh
```

The macOS one on GitHub's Apple Silicon runner: this starts the workflow `build-php.yml` by `gh`,
waits for it and downloads its result:

```bash
php build-php-github.php
```

Then, on any system:

```bash
php build.php
```

Downloads the latest Adminer release, the latest PHP 8.5 for Windows, the extensions and the tools
(7-Zip's `7zr.exe` and installer stub, [rcedit](https://github.com/electron/rcedit)), caches them
in `cache/` and writes `dist/adminer-<version>-x64.exe`,
`dist/adminer-<version>-linux-x86_64.tar.xz` and `dist/adminer-<version>-macos-arm64.zip`. The exe
is built only on Windows, as the Visual C++ runtime is copied from the system, and the
`.tar.xz` needs the `xz` command. Pass a version to build another Adminer release:

```bash
php build.php 6.1.2
```

Or name the platforms to build, e.g. `php build.php windows`. The Windows exe of a release is built
by the workflow [Build Windows](.github/workflows/build-windows.yml) on GitHub Actions.

## Code signing policy

Free code signing provided by [SignPath.io](https://about.signpath.io), certificate by
[SignPath Foundation](https://signpath.org). The signature is pending: the Windows exe stays unsigned
until the SignPath Foundation accepts the project.

Only the Windows exe is signed, and only when built from this repository by the workflow
[Build Windows](.github/workflows/build-windows.yml). It contains binaries of other projects as
they are published: PHP with its libraries and extensions, which are open source, and
Microsoft's Visual C++ runtime, signed by Microsoft.

- Committers and reviewers: [Jakub Vrána](https://github.com/vrana)
- Approvers: [Jakub Vrána](https://github.com/vrana)

Privacy: Adminer asks <https://www.adminer.org/version/> whether there is a newer version, at most
once a day and once a week after a successful answer, sending its own version number. Apart from
that, this program will not transfer any information to other networked systems unless
specifically requested by the user or the person installing or operating it, e.g. by connecting to
a database server.

## License

Apache License 2.0 or GPL 2, the same as Adminer. The bundled PHP, its libraries and the
extensions keep their own licenses. On Windows they are listed in `php/license.txt` and
`php/readme-redist-bins.txt` inside the exe, on Linux and macOS in `php/license/`.
