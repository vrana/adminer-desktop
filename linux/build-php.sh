#!/bin/sh
# Build the static PHP of the Linux download into cache/static-php/VERSION/, which build.php packs.
# Runs on x86_64 Linux; static-php-cli installs its build dependencies with sudo.
# Set GITHUB_TOKEN if GitHub refuses the downloads, it allows 60 anonymous API requests an hour.
set -e
root=$(cd "$(dirname "$0")/.." && pwd)
branch=$(sed -n "s/^const PHP_BRANCH = '\(.*\)';/\1/p" "$root/build.php")
# what Adminer uses besides the core, zlib is for gzip exports
# not iconv, which Adminer uses only for importing UTF-16 files: its GNU libiconv is LGPL, which asks to allow relinking a static binary
extensions=session,mysqli,mysqlnd,pgsql,sqlite3,zlib
# outside /mnt/c in WSL, where compiling would be slow
work=/tmp/adminer-spc
mkdir -p $work
cd $work
curl -fsSL -o spc.tar.gz https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz
tar -xzf spc.tar.gz
./spc doctor --auto-fix
./spc download --with-php="$branch" --for-extensions="$extensions" --prefer-pre-built
./spc build "$extensions" --build-cli
version=$(buildroot/bin/php -n -r 'echo PHP_VERSION;')
target="$root/cache/static-php/$version"
rm -rf "$target"
mkdir -p "$target"
cp buildroot/bin/php "$target/php"
# static-php-cli copies the licenses of everything it built, also of libedit, ncurses and libxml2,
# which PostgreSQL's package brings to the linker but libpq does not use
mkdir "$target/license"
for name in src_php-src lib_openssl lib_postgresql lib_sqlite lib_zlib; do
	cp buildroot/license/${name}_*.txt "$target/license/"
done
echo "$target"
