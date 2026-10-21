<?php
// Windows download, included by build.php

const PHP_BUILD = 'nts-vs17-x64';
const PHP_RELEASES = 'https://windows.php.net/downloads/releases/releases.json';
// the extensions loaded by server.inc.php and the libraries they import
const PHP_FILES = array(
	'php.exe', 'php8.dll', 'license.txt', 'readme-redist-bins.txt',
	'php-win.exe', 'ext/php_ffi.dll', // app.php without a console, FFI starts the server without one too
	'ext/php_mysqli.dll',
	'ext/php_pgsql.dll', 'libpq.dll', 'libssl-3-x64.dll', 'libcrypto-3-x64.dll',
	'ext/php_sqlite3.dll', 'libsqlite3.dll',
);
// the latest GitHub release, which is where PIE takes them from; loaded by server.inc.php only if their client is installed
const EXTENSIONS = array(
	'sqlsrv' => 'microsoft/msphpsql_sqlsrv',
	'oci8' => 'php/pecl-database-oci8',
);
// the Visual C++ runtime, which a clean Windows lacks; Microsoft allows shipping it next to the application
const VC_RUNTIME = array(
	'vcruntime140.dll',
	'vcruntime140_1.dll', 'msvcp140.dll', // only for sqlsrv
);
// the latest GitHub release: [repository, file name]
const TOOLS = array(
	'7zr' => array('ip7z/7zip', '~^7zr\.exe$~'),
	'lzma' => array('ip7z/7zip', '~^lzma\d+\.7z$~'), // the LZMA SDK, the only download with the installer stub 7zSD.sfx
	'rcedit' => array('electron/rcedit', '~^rcedit-x64\.exe$~'),
);

/** Build dist/adminer-VERSION.exe, which shows Adminer in an app window
* @param string $adminer path to adminer.php
* @param string $plugin path to login-ip.php
*/
function build_windows(string $version, string $adminer, string $plugin): void {
	global $root, $cache, $dist;
	$build = "$dist/build";
	$releases = json_decode(file_get_contents(cached_download(PHP_RELEASES, true)), true);
	$php = $releases[PHP_BRANCH][PHP_BUILD]['zip'] ?? null;
	if (!$php) {
		fail("windows.php.net does not offer PHP " . PHP_BRANCH . " " . PHP_BUILD . ".");
	}
	$php_zip = cached_download("https://windows.php.net/downloads/releases/$php[path]");
	$extension_zips = array();
	foreach (EXTENSIONS as $extension => $repository) {
		$extension_zips[$extension] = cached_download(github_asset($repository, '~-' . preg_quote(PHP_BRANCH . '-' . str_replace('x64', 'x86_64', PHP_BUILD)) . '\.zip$~'));
	}
	$tools = array();
	foreach (TOOLS as $name => list($repository, $pattern)) {
		$tools[$name] = cached_download(github_asset($repository, $pattern));
	}

	echo "Assembling dist/build/\n";
	remove_recursive($build);
	copy_recursive("$root/src", $build);
	unlink("$build/launcher.php"); // the browser variant of Linux and macOS, app.php starts the server here
	copy_file(__DIR__ . "/app.php", "$build/app.php");
	copy_file(__DIR__ . "/adminer.ico", "$build/adminer.ico"); // the window icon of app.php
	copy_file($adminer, "$build/adminer.php");
	write_file("$build/adminer-plugins/login-ip.php", file_get_contents($plugin));
	unzip($php_zip, PHP_FILES, "$build/php");
	foreach ($extension_zips as $extension => $filename) {
		// the DLL is named like the zip, with the version in it
		unzip($filename, array(basename($filename, '.zip') . '.dll' => "php_$extension.dll"), "$build/php/ext");
	}
	echo "Copying the Visual C++ runtime from System32\n";
	foreach (VC_RUNTIME as $filename) {
		copy_file(getenv('SystemRoot') . "/System32/$filename", "$build/php/$filename");
	}

	// the stub unpacks the archive to a temporary folder, runs config.txt's RunProgram and deletes the folder when it ends
	echo "Extracting 7zSD.sfx from " . basename($tools['lzma']) . "\n";
	run(array($tools['7zr'], 'e', $tools['lzma'], 'bin/7zSD.sfx', "-o$cache", '-y'));
	$stub = "$dist/stub.exe";
	copy_file("$cache/7zSD.sfx", $stub);
	echo "Setting the icon, manifest and version info of the stub\n";
	preg_match('~^\d+(\.\d+)*~', $version, $match); // rcedit accepts only numbers
	run(array(
		$tools['rcedit'], $stub,
		'--set-icon', __DIR__ . "/adminer.ico",
		// --set-requested-execution-level hangs on a file without a manifest
		'--application-manifest', __DIR__ . "/adminer.manifest",
		'--set-file-version', $match[0],
		'--set-product-version', $version,
		'--set-version-string', 'FileDescription', 'Adminer',
		'--set-version-string', 'ProductName', 'Adminer',
		'--set-version-string', 'InternalName', 'adminer',
		'--set-version-string', 'OriginalFilename', "adminer-$version.exe",
		'--set-version-string', 'CompanyName', 'Jakub Vrana',
		'--set-version-string', 'LegalCopyright', 'Copyright 2007 Jakub Vrana',
	));

	$archive = "$dist/payload.7z";
	@unlink($archive);
	echo "Compressing dist/build/\n";
	run(array($tools['7zr'], 'a', '-mx9', $archive, '.'), $build);

	$exe = "$dist/adminer-$version.exe";
	echo "Writing $exe\n";
	write_file($exe, file_get_contents($stub) . file_get_contents(__DIR__ . "/config.txt") . file_get_contents($archive));
	unlink($stub);
	unlink($archive);
	remove_recursive($build);

	printf("%s (%d kB)\n", $exe, round(filesize($exe) / 1024));
}
