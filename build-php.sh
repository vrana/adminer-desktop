#!/bin/sh
# Build the static PHP for the system it runs on into cache/static-php/OS-ARCH/VERSION/, which build.php packs.
# Runs on x86_64 Linux (WSL works), on Apple Silicon macOS and in Git Bash on Windows; .github/workflows/build-php.yml runs it on the last two.
# static-php-cli installs its build dependencies, with sudo on Linux; on Windows it needs Visual Studio.
# Set GITHUB_TOKEN if GitHub refuses the downloads, it allows 60 anonymous API requests an hour.
set -e
root=$(cd "$(dirname "$0")" && pwd)
branch=$(sed -n "s/^const PHP_BRANCH = '\(.*\)';/\1/p" "$root/build.php")
# what Adminer uses besides the core, zlib is for gzip exports
# not iconv, which Adminer uses only for importing UTF-16 files: its GNU libiconv is LGPL, which asks to allow relinking a static binary
extensions=session,mysqli,mysqlnd,pgsql,sqlite3,zlib
licenses="src_php-src lib_openssl lib_postgresql lib_sqlite lib_zlib"
case "$(uname -s)-$(uname -m)" in
	Linux-x86_64) platform=linux-x86_64; spc=spc-linux-x86_64.tar.gz ;;
	Darwin-arm64) platform=macos-arm64; spc=spc-macos-aarch64.tar.gz ;;
	MINGW64_NT*-x86_64 | MSYS_NT*-x86_64)
		platform=windows-x64; spc=spc-windows-x64.exe
		# sqlsrv imports only Windows' ODBC32.dll, which finds Microsoft's ODBC driver when connecting
		extensions="$extensions,sqlsrv"
		# static-php-cli runs tar with C:\ paths, which Git's GNU tar reads as a remote host; Windows' own tar is bsdtar
		PATH="$(cygpath -u "$SYSTEMROOT")/System32:$PATH"
		;;
	*) echo "Unsupported system $(uname -s)-$(uname -m)." >&2; exit 1 ;;
esac
# outside /mnt/c in WSL, where compiling would be slow
work=/tmp/adminer-spc
mkdir -p $work
cd $work
curl -fsSL -o "$spc" "https://github.com/crazywhalecc/static-php-cli/releases/latest/download/$spc"
case "$spc" in
	*.tar.gz) tar -xzf "$spc" ;;
	*.exe) mv "$spc" spc.exe ;;
esac
./spc doctor --auto-fix
./spc download --with-php="$branch" --for-extensions="$extensions" --prefer-pre-built
./spc build "$extensions" --build-cli
php=$(ls buildroot/bin/php buildroot/bin/php.exe 2>/dev/null | head -1)
version=$("$php" -n -r 'echo PHP_VERSION;')
target="$root/cache/static-php/$platform/$version"
rm -rf "$target"
mkdir -p "$target"
cp "$php" "$target/"
# static-php-cli copies the licenses of everything it built, also of libedit, ncurses and libxml2,
# which PostgreSQL's package brings to the linker but libpq does not use
mkdir "$target/license"
for name in $licenses; do
	cp buildroot/license/${name}*.txt "$target/license/" || echo "No license $name." >&2
done
ls buildroot/license
echo "$target"
