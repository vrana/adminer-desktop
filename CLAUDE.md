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
| `src/launcher.php` | Run by the bundled PHP. Picks the port, starts `php -S`, opens the browser, waits for Enter. |
| `src/www/index.php` | The docroot's only file. Host check, then `include "../adminer.php"`. |
| `build.php` | Downloads Adminer, calls the platform builds; the shared helpers (`cached_download()`, `github_asset()`, …). |
| `windows/build.inc.php` | `build_windows()`: downloads PHP, extensions and tools, writes `dist/adminer-<version>.exe`. |
| `windows/config.txt` | 7-Zip installer config: `RunProgram="php\\php.exe -n launcher.php"`. |
| `windows/adminer.manifest` | `asInvoker`, see below. |
| `windows/adminer.ico` | Rendered once from `adminer/static/logo.svg` (16–256 px PNGs). |
| `linux/build-php.sh` | Run by hand on x86_64 Linux: builds the static PHP with static-php-cli into `cache/static-php/<version>/`. |
| `linux/build.inc.php` | `build_linux()`: packs the newest PHP from `cache/static-php/`, writes `dist/adminer-<version>-linux-x86_64.tar.xz`. |
| `linux/adminer` | The shell script a Linux user starts: `exec php/php -n launcher.php`. |
| `mac/build.inc.php` | `build_mac()`: downloads static-php-cli's prebuilt PHP, writes `dist/adminer-<version>-macos-arm64.zip`. |
| `mac/Info.plist` | The app's plist; `VERSION` is replaced by the Adminer version. |
| `mac/adminer` | The app's executable: `open -a Terminal` with `adminer.command`. |
| `mac/adminer.command` | Run in Terminal: `exec php/php -n launcher.php`, like `linux/adminer`. |
| `mac/adminer.icns` | Rendered once from `adminer/static/logo.svg` (16–1024 px PNGs, one entry per size). |

The exe is `7zSD.sfx` + `config.txt` + a `.7z` of the payload, concatenated. The stub unpacks to
`%TEMP%\7zSxxxxxxxx\`, runs `RunProgram` and deletes the folder when that program ends. Unpacked:

```
adminer.php  adminer-plugins/login-ip.php  launcher.php  www/index.php
php/  php.exe php8.dll + DLLs, ext/php_{mysqli,pgsql,sqlite3,sqlsrv,oci8}.dll
```

The Linux archive holds the same files in `adminer-<version>/`, with the static `php/php` and its
`php/license/` instead of the DLLs, and the `adminer` script. It is unpacked once by the user, not
on every start. The PHP is our own build, with only `session`, `mysqli`, `pgsql`, `sqlite3` and
`zlib` besides the core: 14.8 MB, 4.5 MB in xz. Not `iconv`, which Adminer uses only for importing
UTF-16 files: its GNU libiconv is LGPL, whose relinking condition a static binary would have to
meet, and it was 1 MB. `php/license/` has only the licenses of what is linked in (PHP, OpenSSL,
PostgreSQL, SQLite, zlib); static-php-cli also writes those of libedit, ncurses and libxml2, which
PostgreSQL's package passes to the linker but libpq does not use, and none of their code is in the
binary. static-php-cli's prebuilt binaries were used first and dropped: the *common* build is
UPX-packed, 13 MB in the archive and lacks `mysqli`, the *bulk* build is 30 MB. UPX was measured
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
`setExternalAttributesName()` stores the Unix permissions Archive Utility restores. The PHP is
static-php-cli's prebuilt *common* build for arm64 (Apple Silicon only): it is
44.5 MB unpacked and 15 MB in the zip, has `pdo_mysql` but no `mysqli`, needs macOS 12, links only
system libraries and carries the code signature arm64 requires (`LC_CODE_SIGNATURE`, found by
parsing the load commands). Stripping or changing it would break the signature, re-signing needs a
Mac. Our own lean build like on Linux needs a Mac to run `spc` on. **Nothing of the macOS download
has been run on a Mac yet**: only the zip's structure, permissions, plist and binary headers were
checked. Unverified: that the app opens Terminal as intended, whether
Gatekeeper asks again for `adminer.command` inside an approved app, and the README's *Open Anyway*
steps.

## Build

`linux/build-php.sh` builds the Linux PHP first, on x86_64 Linux (WSL works): it takes a few
minutes in `/tmp/adminer-spc`, installs static-php-cli's dependencies and a musl toolchain with
sudo the first time, and reads `PHP_BRANCH` from `build.php`. Rerun it for PHP security releases;
it needs `GITHUB_TOKEN` (e.g. `GITHUB_TOKEN=$(gh auth token)`) when the anonymous GitHub API limit
is used up.

`php build.php [VERSION]` then builds the Linux and macOS archives on any system and the exe only
on Windows (the Visual C++ runtime is copied from System32). Every download goes through
`cached_download()` into `cache/<URL path>`; the path holds the version even where the file name
does not (`releases/download/26.04/7zr.exe`). `releases.json`, the GitHub API answers and
static-php-cli's listing are refreshed after a day, everything else is kept forever. Deleting
`cache/` also deletes the built PHP. There are no hashes: they would come from the same server as
the files, which HTTPS already covers.

Versions are never pinned: the latest Adminer release, the latest PHP of `PHP_BRANCH` (on
windows.php.net for Windows, from php.net through static-php-cli for Linux), and the latest GitHub
release of static-php-cli, sqlsrv, oci8, 7-Zip and rcedit. The extension zips must match
`PHP_BRANCH`-`PHP_BUILD`; when PHP's branch is raised, check that both extensions publish a build
for it. The unauthenticated GitHub API allows 60 requests an hour, which the one-day cache keeps
far away; when it does run out, `gh api repos/<repo>/releases/latest > cache/repos/<repo>/releases/latest`
fills the cache under the token's own limit.

There is no test suite and no linter. `php -l` on the six PHP files and a `php build.php` are the
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
- **OpenSSL is needed only by `libpq.dll`.** `php_openssl.dll` was dropped: MySQL's
  `caching_sha2_password` login works without it even after `FLUSH PRIVILEGES`, and Adminer calls
  no `openssl_*` function. `libssl`/`libcrypto` stay for PostgreSQL and are 1.9 MB of the 7 MB.
- **sqlsrv imports only Windows' `ODBC32.dll`**, plus `msvcp140.dll` and `vcruntime140_1.dll`,
  which are shipped for it; Microsoft's ODBC driver is looked up only when connecting.
- **A statically linked oci8 would stop the whole exe** without Instant Client, as `oci.dll` would
  become an import of the exe. As a separate DLL a missing `oci.dll` only means a startup warning,
  which the launcher avoids by loading oci8 only when `oci.dll` is in `PATH`.

## Testing

`launcher.php` and the exe open a browser tab and a console window, so automated tests extract the
built exe and run its server directly:

```bash
7z x -o<scratch>/x dist/adminer-<version>.exe
cd <scratch>/x && php/php.exe -n -d extension_dir=php/ext -d extension=mysqli -d extension=pgsql -d extension=sqlite3 -d extension=sqlsrv -d extension=oci8 -S 127.0.0.1:<port> -t www
```

and drive it with a curl script (log in, select, export, import), as for the `adminer` repo.
Use a port other than 23646, which a running Adminer may hold, and stop the server by its port in
the command line rather than every `php.exe`. A wrong `Host` header must get 403.

On Linux, the launcher can run without opening anything with a fake `xdg-open` first in `PATH` (a
script that only logs its argument) and stdin kept open (`sleep 60 | adminer-<version>/adminer`);
it stops when stdin ends. The bundled PHP has no curl, so run a curl script with another PHP. A
database server the test machine cannot reach still shows which extension Adminer picked: mysqli
answers "Operation timed out", PDO `SQLSTATE[HY000] [2002]`.

## Rules

- **Never edit `adminer.php`** or the plugin; they are downloaded from the release.
- Match Adminer's style: tabs, doc comments in its voice.
- Write only measured facts in comments and docs.
