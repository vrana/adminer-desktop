<?php
/* Assemble the Windows download in dist/.
* Usage: php build.php [VERSION]
* Adminer is downloaded from GitHub, the latest release unless a version is given.
* PHP and the tools come from their official downloads, cached in cache/.
* Windows only: the Visual C++ runtime is copied from the system.
*/

// the latest patch release of this branch is taken, so a rebuild picks up PHP's security fixes
const PHP_BRANCH = '8.5';
const PHP_BUILD = 'nts-vs17-x64';
const PHP_RELEASES = 'https://windows.php.net/downloads/releases/releases.json';
// the extensions loaded by launcher.php and the libraries they import
const PHP_FILES = array(
	'php.exe', 'php8.dll', 'license.txt', 'readme-redist-bins.txt',
	'ext/php_mysqli.dll',
	'ext/php_pgsql.dll', 'libpq.dll', 'libssl-3-x64.dll', 'libcrypto-3-x64.dll',
	'ext/php_sqlite3.dll', 'libsqlite3.dll',
);
// the latest GitHub release, which is where PIE takes them from; loaded by launcher.php only if their client is installed
const EXTENSIONS = array(
	'sqlsrv' => 'microsoft/msphpsql_sqlsrv',
	'oci8' => 'php/pecl-database-oci8',
);
// the Visual C++ runtime, which a clean Windows lacks; Microsoft allows shipping it next to the application
const VC_RUNTIME = array(
	'vcruntime140.dll',
	'vcruntime140_1.dll', 'msvcp140.dll', // only for sqlsrv
);
const PLUGIN_URL ='https://raw.githubusercontent.com/vrana/adminer/v%s/plugins/login-ip.php';
// the latest GitHub release: [repository, file name]
const TOOLS = array(
	'7zr' => array('ip7z/7zip', '~^7zr\.exe$~'),
	'lzma' => array('ip7z/7zip', '~^lzma\d+\.7z$~'), // the LZMA SDK, the only download with the installer stub 7zSD.sfx
	'rcedit' => array('electron/rcedit', '~^rcedit-x64\.exe$~'),
);

if (PHP_OS_FAMILY != 'Windows') {
	fail("Run this on Windows, the Visual C++ runtime is taken from the system.");
}
$root = __DIR__;
$cache = "$root/cache";
$dist = "$root/dist";
$build = "$dist/build";

$version = $argv[1] ?? '';
if ($version == "") {
	preg_match('~/download/v([^/]+)/~', github_asset('vrana/adminer', '~^adminer-[\d.]+\.php$~'), $match);
	$version = $match[1];
}
$adminer = cached_download("https://github.com/vrana/adminer/releases/download/v$version/adminer-$version.php");
$plugin = cached_download(sprintf(PLUGIN_URL, $version));

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
	'--set-icon', "$root/windows/adminer.ico",
	// --set-requested-execution-level hangs on a file without a manifest
	'--application-manifest', "$root/windows/adminer.manifest",
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
write_file($exe, file_get_contents($stub) . file_get_contents("$root/windows/config.txt") . file_get_contents($archive));
echo "Removing the temporary files\n";
unlink($stub);
unlink($archive);
remove_recursive($build);

printf("%s (%d kB)\n", $exe, round(filesize($exe) / 1024));

/** Find a file in the latest release of a GitHub repository
* @param string $pattern regular expression matching the file name
* @return string URL
*/
function github_asset(string $repository, string $pattern): string {
	// the API allows 60 requests an hour
	$release = json_decode(file_get_contents(cached_download("https://api.github.com/repos/$repository/releases/latest", true)), true);
	foreach ($release['assets'] ?? array() as $asset) {
		if (preg_match($pattern, $asset['name'])) {
			return $asset['browser_download_url'];
		}
	}
	fail("The latest release of $repository has no file matching $pattern.");
}

/** Download a file to cache/ unless it is already there
* @param bool $daily the file changes, refresh it after a day
* @return string path to the file
*/
function cached_download(string $url, bool $daily = false): string {
	global $cache;
	// the path of the URL contains the version also where the file name does not, e.g. releases/download/26.04/7zr.exe
	$filename = "$cache/" . preg_replace('~^https://[^/]+/~', '', $url);
	if (file_exists($filename) && (!$daily || filemtime($filename) > time() - 24 * 60 * 60)) {
		echo "Taking $url from cache/\n";
	} else {
		echo "Downloading $url\n";
		// GitHub API refuses requests without User-Agent
		$context = stream_context_create(array('http' => array('header' => "User-Agent: vrana/adminer-desktop\r\n")));
		$file = @file_get_contents($url, false, $context);
		if ($file === false) {
			// the last status line, a redirect comes first
			$statuses = preg_grep('~^HTTP/~', $http_response_header ?? array());
			fail("Cannot download $url: " . ($statuses ? end($statuses) : error_get_last()['message']));
		}
		write_file($filename, $file);
	}
	return $filename;
}

/** Extract files from a zip
* @param array<int|string, string> $filenames [name] keeps the path, [name => new name] renames
*/
function unzip(string $filename, array $filenames, string $target): void {
	echo "Extracting from " . basename($filename) . "\n";
	$zip = new ZipArchive;
	if ($zip->open($filename) !== true) {
		fail("Cannot open $filename.");
	}
	foreach ($filenames as $name => $new_name) {
		$name = (is_int($name) ? $new_name : $name);
		$content = $zip->getFromName($name);
		if ($content === false) {
			fail("$name is not in $filename.");
		}
		write_file("$target/$new_name", $content);
	}
	$zip->close();
}

function write_file(string $filename, string $content): void {
	if (!is_dir(dirname($filename)) && !mkdir(dirname($filename), 0755, true)) {
		fail("Cannot create " . dirname($filename) . ".");
	}
	if (file_put_contents($filename, $content) === false) {
		fail("Cannot write $filename.");
	}
}

function copy_recursive(string $source, string $target): void {
	if (!mkdir($target, 0755, true)) {
		fail("Cannot create $target.");
	}
	foreach (scandir($source) as $file) {
		if ($file === '.' || $file === '..') {
			continue;
		}
		if (is_dir("$source/$file")) {
			copy_recursive("$source/$file", "$target/$file");
		} else {
			copy_file("$source/$file", "$target/$file");
		}
	}
}

function copy_file(string $source, string $target): void {
	if (!copy($source, $target)) {
		fail("Cannot copy $source to $target.");
	}
}

function remove_recursive(string $path): void {
	if (!file_exists($path)) {
		return;
	}
	foreach (scandir($path) as $file) {
		if ($file === '.' || $file === '..') {
			continue;
		}
		$full = "$path/$file";
		is_dir($full) ? remove_recursive($full) : unlink($full);
	}
	rmdir($path);
}

/** Run a program without a shell
* @param list<string> $command
*/
function run(array $command, ?string $cwd = null): void {
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, $cwd);
	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	if (proc_close($process)) {
		fail(implode(" ", $command) . " failed:\n$output");
	}
}

/** @return never */
function fail(string $message) {
	fwrite(STDERR, "$message\n");
	exit(1);
}
