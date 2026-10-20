<?php
/* Assemble the Windows, Linux and macOS downloads in dist/.
* Usage: php build.php [VERSION]
* Adminer is downloaded from GitHub, the latest release unless a version is given.
* PHP and the tools come from their official downloads, cached in cache/.
* The Windows download is built only on Windows, the Visual C++ runtime is copied from the system.
*/

// the latest patch release of this branch is taken, so a rebuild picks up PHP's security fixes
const PHP_BRANCH = '8.5';
const PLUGIN_URL ='https://raw.githubusercontent.com/vrana/adminer/v%s/plugins/login-ip.php';

include __DIR__ . "/windows/build.inc.php";
include __DIR__ . "/linux/build.inc.php";
include __DIR__ . "/mac/build.inc.php";

$root = __DIR__;
$cache = "$root/cache";
$dist = "$root/dist";

$version = $argv[1] ?? '';
if ($version == "") {
	preg_match('~/download/v([^/]+)/~', github_asset('vrana/adminer', '~^adminer-[\d.]+\.php$~'), $match);
	$version = $match[1];
}
$adminer = cached_download("https://github.com/vrana/adminer/releases/download/v$version/adminer-$version.php");
$plugin = cached_download(sprintf(PLUGIN_URL, $version));

if (PHP_OS_FAMILY == 'Windows') {
	echo "\nBuilding Windows\n";
	build_windows($version, $adminer, $plugin);
} else {
	echo "\nSkipping Windows, the Visual C++ runtime is taken from the system\n";
}

echo "\nBuilding Linux\n";
build_linux($version, $adminer, $plugin);

echo "\nBuilding macOS\n";
build_mac($version, $adminer, $plugin);

/** Find the newest static PHP of PHP_BRANCH built by build-php.sh
* @param string $platform e.g. linux-x86_64
* @param string $how how to build it if there is none
* @return string directory with php and license/
*/
function static_php(string $platform, string $how): string {
	global $cache;
	$php_version = '';
	foreach (glob("$cache/static-php/$platform/*/php") as $filename) {
		$dir_version = basename(dirname($filename));
		if (preg_match('~^' . preg_quote(PHP_BRANCH) . '\.~', $dir_version) && version_compare($dir_version, $php_version) > 0) {
			$php_version = $dir_version;
		}
	}
	if ($php_version == '') {
		fail("cache/static-php/$platform/ has no PHP " . PHP_BRANCH . ", $how first.");
	}
	echo "Taking PHP $php_version from cache/static-php/$platform/\n";
	return "$cache/static-php/$platform/$php_version";
}

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
	$filename = "$cache/" . str_replace('?', '', preg_replace('~^https://[^/]+/~', '', $url)); // Windows does not allow ? in file names
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
