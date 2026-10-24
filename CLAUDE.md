# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Adminer with its own PHP for people without PHP: one Windows executable, a Linux `.tar.xz` and a
macOS `.zip` with `Adminer.app`. It is packaging, not a fork: the compiled release `adminer.php`
stays untouched, everything specific to the desktop is in the small files around it, and all
platforms share `src/`.

## Layout

| Path | What it is |
| --- | --- |
| `src/launcher.php` | Run by the bundled PHP on Linux and macOS. Picks the port, starts `php -S`, opens an `--app` window of Chrome, Edge, Brave or Chromium, or the default browser without them, waits for Enter. |
| `src/server.inc.php` | What `launcher.php` and `windows/app.php` share: the port, `port_state()`, `server_command()`, `extensions()`. |
| `src/www/index.php` | The docroot's only script. Host check, then `include "../adminer.php"`. |
| `build.php` | Downloads Adminer, calls the platform builds; the shared helpers (`cached_download()`, `github_asset()`, `static_php()`, …). `plugins()` takes login-ip and every plugin declaring `static $optional = true` from the release's `adminer-<version>.zip`, plus config.php and an `adminer-plugins.php` disabling the optional ones; users enable them on the Configuration page. It also takes the designs plugin with `designs/*/*.css` into `www/designs/`, where users choose one on the Configuration page. `drivers()` compiles the plugin drivers in `DRIVERS` (ClickHouse, Elasticsearch, Redis: those needing no extension missing in some bundled PHP) by `compile.php drivers` of the same zip in `dist/drivers/`, which writes only `drivers/`: the sources work only with the development version, and the compiled files are on adminer.org only after the desktop is built. With PHP 8.5 the 6.2.0 drivers came out identical to adminer.org's, so their checksums match those inlined in `adminer.php`. The zip of the tag would not do: it lacks the submodules with the JUSH modules and PhpShrink. |
| `build-php.sh` | Builds the static PHP with static-php-cli on x86_64 Linux or Apple Silicon macOS into `cache/static-php/<os>-<arch>/<version>/`; the latest release of `PHP_BRANCH` unless `cache/spc-downloads/` already holds one, delete it for a newer PHP. |
| `.github/workflows/build-php.yml` | Runs `build-php.sh` on GitHub's `macos-15` runner, uploads the `php-macos-arm64` artifact. |
| `windows/build.inc.php` | `build_windows()`: downloads PHP, extensions and tools, writes `dist/adminer-<version>.exe`. |
| `windows/config.txt` | 7-Zip installer config: `RunProgram` is `php-win.exe` with FFI running `app.php`. |
| `windows/app.php` | Run by `php-win.exe` from `config.txt`: the server without a console, Adminer in an app window of Chrome, Edge, Brave or Chromium. Windows has no `launcher.php`, the browser variant of Linux and macOS. |
| `windows/adminer.manifest` | `asInvoker`, see below. |
| `windows/adminer.ico` | Rendered once from `adminer/static/logo.svg`, each size separately (20, 24, 30, 32, 36, 40, 48, 64, 256 px PNGs), and the 16 px pixel art of Adminer's favicon (a 4-bit BMP; the rendered 16 px blurs): the exe's icon and the window icons of `app.php`. |
| `linux/build.inc.php` | `build_linux()`: packs the newest PHP from `cache/static-php/linux-x86_64/`, writes `dist/adminer-<version>-linux-x86_64.tar.xz`. |
| `linux/adminer` | The shell script a Linux user starts: `exec php/php -n launcher.php`. |
| `build-php-github.php` | Starts the workflow by `gh`, waits for it and downloads its artifacts into `cache/static-php/<platform>/`. |
| `mac/build.inc.php` | `build_mac()`: packs the newest PHP from `cache/static-php/macos-arm64/`, writes `dist/adminer-<version>-macos-arm64.zip`. |
| `mac/Info.plist` | The app's plist; `VERSION` is replaced by the Adminer version. |
| `mac/adminer` | The app's executable: `open -a Terminal` with `adminer.command`. |
| `mac/adminer.command` | Run in Terminal: `exec php/php -n launcher.php`, like `linux/adminer`. |
| `mac/adminer.icns` | Rendered once from `adminer/static/logo.svg` (32–1024 px PNGs, one entry per size), with the 16 px pixel art of Adminer's favicon. |

The exe is `7zSD.sfx` + `config.txt` + a `.7z` of the payload, concatenated. The stub unpacks to
`%TEMP%\7zSxxxxxxxx\`, runs `RunProgram` and deletes the folder when that program ends. Unpacked:

```
adminer.php  adminer-plugins.php  adminer-plugins/*.php  server.inc.php  app.php  adminer.ico  www/index.php  www/designs/*/*.css
php/  php.exe php-win.exe php8.dll + DLLs, ext/php_{mysqli,pgsql,sqlite3,sqlsrv,oci8,ffi}.dll
```

`php -S` serves the designs in `www/designs/` as files, without the host check of `index.php`; they
are the public CSS of the Adminer repository. The designs plugin links them relative to the page
(`designs/<name>/adminer.css`) and reads the same path relative to `$_SERVER["SCRIPT_FILENAME"]`,
which is `www/index.php` also after its `chdir()`, to find out whether a design handles the dark
mode itself.

`config.txt`'s `RunProgram` is `php-win.exe` (PHP built as a GUI program, no console) running `app.php`.
`php-win.exe -S` does not serve, so `app.php` starts `php.exe -S` by `CreateProcessW` with
`CREATE_NO_WINDOW` through FFI (`php_ffi.dll`, `-d ffi.enable=1`); PHP's `proc_open()` has no such
flag and a console program started from one without a console gets a window of its own. It then runs
the first of Chrome, Edge, Brave and Chromium, as `launcher.php` does, with `--app=URL` (a window
without tabs and the address bar) and the user's own profile: a profile of its own got signed in,
synced the extensions and grew to 580 MB. Tried with Edge and Chrome. The browser already running takes
the window over and the started process exits at once, so `app.php` polls the windows of class
`Chrome_WidgetWin_1` every half second instead: those that were not there before and are titled by
Adminer are ours, also those opened by a second start. When all of them are gone,
`app.php` stops the server. Window handles are declared `intptr_t` in the FFI definitions: casting
the `void *` of an `EnumWindows` callback crashed PHP. The browser gives the taskbar Adminer's 16 px
favicon scaled up, so `app.php` sets the windows' icons itself by `WM_SETICON`, loaded from
`adminer.ico` at 16 and 24 px times the window's DPI / 96, again when the browser replaces them,
polled every 0.1 s until the first window appears. The title bar is drawn by the browser from the
favicon, the window's small icon does not show there; Adminer's own 16 px favicon looks right there,
multi-size favicons, smooth or pixel art, looked blurred. Errors are message boxes; without these
browsers, the default browser opens and a message box keeps the server until it is clicked. Nothing
reads the registry, as `reg.exe` would flash a console window.

The Linux archive holds the same files in `adminer-<version>/`, with the static `php/php` and its
`php/license/` instead of the DLLs, and the `adminer` script. It is unpacked once by the user, not
on every start. The PHP is our own build, with only `session`, `mysqli`, `openssl`, `pgsql`,
`sqlite3` and `zlib` besides the core: 17.0 MB, 4.6 MB in xz; `openssl`, for HTTPS and TLS, added
88 kB in xz, as libpq links OpenSSL anyway. It reads the CA certificates from `/etc/ssl/cert.pem`
or `/etc/ssl/certs/`, tried only on Ubuntu. Not `iconv`, which Adminer uses only for importing
UTF-16 files: its GNU libiconv is LGPL, whose relinking condition a static binary would have to
meet, and it was 1 MB. `php/license/` has only the licenses of what is linked in (PHP, OpenSSL,
PostgreSQL, SQLite, zlib); static-php-cli also writes those of libedit, ncurses, libxml2 and
libiconv, which PostgreSQL's package passes to the linker but libpq does not use, and none of their
code is in the binary. static-php-cli's prebuilt binaries were used first and dropped: the *common*
build is UPX-packed, 13 MB in the archive and lacks `mysqli`, the *bulk* build is 30 MB. UPX was measured
and rejected: it saves 9.4 MB on disk (6.4 MB binary) but adds 1.2 MB to the xz download,
65 ms to each of the two PHP starts and 8.7 MB of memory to each process, as the unpacked copy is
private memory. The archive is written with `PharData`, which sets the executable bit of `adminer`
and `php/php` also on Windows, and compressed by the `xz` command, because PharData knows only gzip
and bzip2; xz makes 4.8 MB of what gzip makes 6.0 MB. On Windows `xz` is on the `PATH` only in Git
Bash (`C:\Program Files\Git\ucrt64\bin`), not in PowerShell or cmd. Only x86_64 is built, as
nothing here can build or run aarch64.

The macOS zip holds `Adminer.app/Contents/` with `Info.plist`, `MacOS/adminer` and the payload in
`Resources/`. A `.app` has no console, but the launcher waits for Enter, so the executable opens
`Resources/adminer.command` in Terminal. The zip is written with `ZipArchive`, whose
`setExternalAttributesName()` stores the Unix permissions Archive Utility restores. The PHP is the
same lean build as on Linux, for arm64 (Apple Silicon only), built by `build-php.sh` on GitHub's
`macos-15` runner, as `spc` needs a Mac; the artifact loses the executable bit, which the zip sets
again. arm64 code must be signed: Apple's linker signs it ad-hoc (`LC_CODE_SIGNATURE`), so changing
the binary afterwards, e.g. stripping it, would break the signature, and re-signing needs a Mac or
`rcodesign`. The built PHP is 15.6 MB (before `openssl`), needs macOS 12 and makes a 6.8 MB zip;
static-php-cli's prebuilt *common* build used first was 44.5 MB, a 15 MB zip, and had `pdo_mysql` but no `mysqli`.
**Nothing of the macOS download has been run on a Mac yet**: only the zip's structure, permissions,
plist and the binary's headers and signature were checked. Unverified: that the app opens Terminal
as intended, whether Gatekeeper asks again for `adminer.command` inside an approved app, and the
README's *Open Anyway* steps.

## Build

The static PHP of Linux and macOS is built first, by `build-php.sh`, which reads `PHP_BRANCH` from
`build.php` and has the only list of the extensions. On x86_64 Linux (WSL works) it runs by hand:
it takes a few minutes in `/tmp/adminer-spc`, installs static-php-cli's dependencies and a musl
toolchain with sudo the first time, and needs `GITHUB_TOKEN` (e.g. `GITHUB_TOKEN=$(gh auth token)`)
when the anonymous GitHub API limit is used up. For macOS, `php build-php-github.php` runs it on
GitHub: the workflow builds what is pushed, and its artifacts are downloadable only when signed in,
so nothing is published. Rerun both for PHP security releases; `build.php` packs the newest PHP of
`PHP_BRANCH` it finds.

`php build.php [VERSION]` then builds the Linux and macOS archives on any system and the exe only
on Windows (the Visual C++ runtime is copied from System32). Every download goes through
`cached_download()` into `cache/<URL path>`; the path holds the version even where the file name
does not (`releases/download/26.04/7zr.exe`). `releases.json` and the GitHub API answers are
refreshed after a day, everything else is kept forever. Deleting `cache/` also deletes the built
PHP. There are no hashes: they would come from the same server as the files, which HTTPS already
covers.

Versions are never pinned: the latest Adminer release, the latest PHP of `PHP_BRANCH` (on
windows.php.net for Windows, from php.net through static-php-cli for Linux and macOS), and the latest GitHub
release of static-php-cli, sqlsrv, oci8, 7-Zip and rcedit. The extension zips must match
`PHP_BRANCH`-`PHP_BUILD`; when PHP's branch is raised, check that both extensions publish a build
for it. The unauthenticated GitHub API allows 60 requests an hour, which the one-day cache keeps
far away; when it does run out, `gh api repos/<repo>/releases/latest > cache/repos/<repo>/releases/latest`
fills the cache under the token's own limit.

There is no test suite and no linter. `php -l` on the seven PHP files and a `php build.php` are the
check, plus the HTTP check below for anything that changes the payload.

A release follows each Adminer release: `build.php` for that version, a `v<VERSION>` tag here and a
GitHub release with the three files, which www.adminer.org links. Rebuild the Linux PHP first when
PHP had a release.

## Things that were found the hard way

- **Windows ran the first exe as administrator.** The stub's version info says "7z Setup SFX" and
  it has no manifest, so Windows' installer detection elevates it (silently where UAC is set to
  never notify). The embedded `adminer.manifest` with `asInvoker` fixes it.
  Proof that it is not elevated: a non-elevated shell can read the command line of its `php.exe`.
- **rcedit's `--set-requested-execution-level` hangs** on a file without a manifest. Pass the
  whole manifest with `--application-manifest`.
- **7-Zip Extra no longer contains the installer stubs**; they are only in the LZMA SDK
  (`lzmaNNNN.7z`, `bin/7zSD.sfx`). `7z.sfx` and `7zCon.sfx` of a normal 7-Zip install only extract
  and run nothing.
- **A test bind succeeds on a port in use** on Windows, because PHP sets `SO_REUSEADDR`. The
  launcher probes by connecting instead, with a 0.1 s timeout, because Windows retries a refused
  connection for 2 seconds. A listening server accepts at once.
- **`php -S` runs scripts with the docroot as working directory**, and Adminer looks for
  `adminer-plugins/` in the working directory, hence the `chdir()` in `index.php`.
- **"Opcode handlers are unusable due to ASLR"** killed the server three times in a row during
  testing and could not be reproduced afterwards. Windows shares OPcache among processes of the
  same PHP build; the launcher gives each server its own `opcache.cache_id` (its process id).
  OPcache stays on: a page takes about 4 ms with it, 27 ms without.
- **`php_openssl.dll` is needed for HTTPS and TLS**, not by Adminer's own code: without it PHP
  has no `https` wrapper and no `ssl`/`tls` transport, so the HTTPS drivers, Redis over TLS and
  MySQL with SSL fail. It adds 53 kB to the exe, as `libssl`/`libcrypto` are shipped for
  `libpq.dll` anyway. Without a CA file it verifies against Windows' certificate store.
- **sqlsrv imports only Windows' `ODBC32.dll`**, plus `msvcp140.dll` and `vcruntime140_1.dll`,
  which are shipped for it; Microsoft's ODBC driver is looked up only when connecting.
- **A statically linked oci8 would stop the whole exe** without Instant Client, as `oci.dll` would
  become an import of the exe. As a separate DLL a missing `oci.dll` only means a startup warning,
  which the launcher avoids by loading oci8 only when `oci.dll` is in `PATH`.
- **A static PHP for Windows was tried and not adopted.** `build-php.sh` builds one in Git Bash
  (with `sqlsrv` compiled in, and Windows' own `tar` first in `PATH`: static-php-cli runs `tar` with
  `C:\` paths, which Git's GNU tar reads as a remote host). Its `php.exe` worked with MySQL,
  PostgreSQL, MS SQL and SQLite, but it still imports `libpq.dll`, which needs OpenSSL and
  `vcruntime140.dll`, it cannot load extension DLLs, so no optional oci8, and it saved only 1.7 MB
  (3.2 MB in 7z instead of the official 4.9 MB). The workflow no longer builds it.

## Testing

`launcher.php` opens a browser tab and the exe an app window, so automated tests extract the
built exe and run its server directly:

```bash
7z x -o<scratch>/x dist/adminer-<version>.exe
cd <scratch>/x && php/php.exe -n -d extension_dir=php/ext -d extension=mysqli -d extension=pgsql -d extension=sqlite3 -d extension=sqlsrv -d extension=oci8 -S 127.0.0.1:<port> -t www
```

and drive it with a curl script (log in, select, export, import), as for the `adminer` repo.
Use a port other than 23646, which a running Adminer may hold, and stop the server by its port in
the command line rather than every `php.exe`. A wrong `Host` header must get 403.

On Linux, the launcher can run without opening anything with a fake `xdg-open` first in `PATH` (a
script that only logs its argument; a fake `google-chrome` gets `--app=URL` instead, in a session of
its own by `setsid`; a WSL with WSLg would show a real browser's window) and stdin kept open
(`sleep 60 | adminer-<version>/adminer`); it stops when stdin ends. The bundled PHP has no curl, so run a curl script with another PHP. A
database server the test machine cannot reach still shows which extension Adminer picked: mysqli
answers "Operation timed out", PDO `SQLSTATE[HY000] [2002]`.

## Rules

- **Never edit `adminer.php`** or the plugins; they are downloaded from the release.
- Match Adminer's style: tabs, doc comments in its voice.
- Write only measured facts in comments and docs.
