<?php
// macOS download, included by build.php

// static-php-cli's prebuilt build with pgsql, sqlite3 and pdo_mysql compiled in; Adminer uses PDO_MySQL when mysqli is missing
const MAC_PHP = 'https://dl.static-php.dev/static-php-cli/common/';

/** Build dist/adminer-VERSION-macos-arm64.zip with Adminer.app
* @param string $adminer path to adminer.php
* @param string $plugin path to login-ip.php
*/
function build_mac(string $version, string $adminer, string $plugin): void {
	global $root, $dist;
	$listing = json_decode(file_get_contents(cached_download(MAC_PHP . '?format=json', true)), true);
	$php_version = '';
	foreach ($listing as $file) {
		if (preg_match('~^php-(' . preg_quote(PHP_BRANCH) . '\.\d+)-cli-macos-aarch64\.tar\.gz$~', $file['name'], $match) && version_compare($match[1], $php_version) > 0) {
			$php_version = $match[1];
		}
	}
	if ($php_version == '') {
		fail("static-php-cli does not offer PHP " . PHP_BRANCH . " for macOS.");
	}
	$php = new PharData(cached_download(MAC_PHP . "php-$php_version-cli-macos-aarch64.tar.gz"));

	$filename = "$dist/adminer-$version-macos-arm64.zip";
	echo "Writing $filename\n";
	@unlink($filename);
	$zip = new ZipArchive;
	if ($zip->open($filename, ZipArchive::CREATE) !== true) {
		fail("Cannot create $filename.");
	}
	$app = "Adminer.app/Contents";
	$files = array(
		"$app/Info.plist" => str_replace('VERSION', $version, file_get_contents(__DIR__ . "/Info.plist")),
		"$app/MacOS/adminer" => file_get_contents(__DIR__ . "/adminer"),
		"$app/Resources/adminer.icns" => file_get_contents(__DIR__ . "/adminer.icns"),
		"$app/Resources/adminer.command" => file_get_contents(__DIR__ . "/adminer.command"),
		"$app/Resources/php/php" => $php['php']->getContent(),
		"$app/Resources/adminer.php" => file_get_contents($adminer),
		"$app/Resources/adminer-plugins/login-ip.php" => file_get_contents($plugin),
		"$app/Resources/launcher.php" => file_get_contents("$root/src/launcher.php"),
		"$app/Resources/www/index.php" => file_get_contents("$root/src/www/index.php"),
	);
	$executables = array("$app/MacOS/adminer", "$app/Resources/adminer.command", "$app/Resources/php/php");
	foreach ($files as $name => $content) {
		$zip->addFromString($name, $content);
		$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9);
		// macOS' Archive Utility takes the permissions from the Unix part of the external attributes
		$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (in_array($name, $executables) ? 0100755 : 0100644) << 16);
	}
	unset($php); // PharData keeps the file open
	if (!$zip->close()) {
		fail("Cannot write $filename.");
	}
	printf("%s (%d kB)\n", $filename, round(filesize($filename) / 1024));
}
