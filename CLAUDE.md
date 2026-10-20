# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Adminer as one Windows executable for people without PHP. It is packaging, not a fork: the
compiled release `adminer.php` stays untouched, everything specific to the desktop is in the
small files around it. Only Windows exists so far; macOS (an `.app` in a zip) and Linux (a
static PHP from static-php-cli) were planned around the same `src/` but not started.

## Layout

| Path | What it is |
| --- | --- |
| `src/launcher.php` | Run by the bundled PHP. Picks the port, starts `php -S`, opens the browser, waits for Enter. |
| `src/www/index.php` | The docroot's only file. Host check, then `include "../adminer.php"`. |
| `windows/config.txt` | 7-Zip installer config: `RunProgram="php\\php.exe -n launcher.php"`. |
| `windows/adminer.manifest` | `asInvoker`, see below. |
| `windows/adminer.ico` | Rendered once from `adminer/static/logo.svg` (16–256 px PNGs). |
| `build.php` | Downloads everything, assembles `dist/build/`, writes `dist/adminer-<version>.exe`. |

The exe is `7zSD.sfx` + `config.txt` + a `.7z` of the payload, concatenated. The stub unpacks to
`%TEMP%\7zSxxxxxxxx\`, runs `RunProgram` and deletes the folder when that program ends. Unpacked:

```
adminer.php  adminer-plugins/login-ip.php  launcher.php  www/index.php
php/  php.exe php8.dll + DLLs, ext/php_{mysqli,pgsql,sqlite3,sqlsrv,oci8}.dll
```

## Build

`php build.php [VERSION]`, Windows only (the Visual C++ runtime is copied from System32). Every
download goes through `cached_download()` into `cache/<URL path>`; the path holds the version even
where the file name does not (`releases/download/26.04/7zr.exe`). `releases.json` and the GitHub
API answers are refreshed after a day, everything else is kept forever. Delete `cache/` to start
over. There are no hashes: they would come from the same server as the files, which HTTPS already
covers.

Versions are never pinned: the latest Adminer release, the latest PHP of `PHP_BRANCH`, and the
latest GitHub release of sqlsrv, oci8, 7-Zip and rcedit. The extension zips must match
`PHP_BRANCH`-`PHP_BUILD`; when PHP's branch is raised, check that both extensions publish a build
for it. The unauthenticated GitHub API allows 60 requests an hour, which the one-day cache keeps
far away; when it does run out, `gh api repos/<repo>/releases/latest > cache/repos/<repo>/releases/latest`
fills the cache under the token's own limit.

There is no test suite and no linter. `php -l` on the three PHP files and a `php build.php` are the
check, plus the HTTP check below for anything that changes the payload.

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

## Rules

- **Never edit `adminer.php`** or the plugin; they are downloaded from the release.
- Match Adminer's style: tabs, doc comments in its voice.
- Write only measured facts in comments and docs.
