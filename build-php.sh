#!/bin/sh
# Build the static PHP for the system it runs on into cache/static-php/OS-ARCH/VERSION/, which build.php packs.
# Runs on x86_64 Linux (WSL works) and on Apple Silicon macOS, where .github/workflows/build-php.yml runs it.
# static-php-cli installs its build dependencies, with sudo on Linux.
# Set GITHUB_TOKEN if GitHub refuses the downloads, it allows 60 anonymous API requests an hour.
set -e
root=$(cd "$(dirname "$0")" && pwd)
branch=$(sed -n "s/^const PHP_BRANCH = '\(.*\)';/\1/p" "$root/build.php")
# what Adminer uses besides the core, zlib is for gzip exports
# not iconv, which Adminer uses only for importing UTF-16 files: its GNU libiconv is LGPL, which asks to allow relinking a static binary
extensions=session,mysqli,mysqlnd,pgsql,sqlite3,zlib
case "$(uname -s)-$(uname -m)" in
	Linux-x86_64) platform=linux-x86_64; spc=spc-linux-x86_64 ;;
	Darwin-arm64) platform=macos-arm64; spc=spc-macos-aarch64 ;;
	*) echo "Unsupported system $(uname -s)-$(uname -m)." >&2; exit 1 ;;
esac
# outside /mnt/c in WSL, where compiling would be slow
work=/tmp/adminer-spc
mkdir -p $work
cd $work
curl -fsSL -o spc.tar.gz "https://github.com/crazywhalecc/static-php-cli/releases/latest/download/$spc.tar.gz"
tar -xzf spc.tar.gz
./spc doctor --auto-fix
./spc download --with-php="$branch" --for-extensions="$extensions" --prefer-pre-built
./spc build "$extensions" --build-cli
version=$(buildroot/bin/php -n -r 'echo PHP_VERSION;')
target="$root/cache/static-php/$platform/$version"
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
