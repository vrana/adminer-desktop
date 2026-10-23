<?php
// macOS download, included by build.php

/** Build dist/adminer-VERSION-macos-arm64.zip with Adminer.app
* @param string $adminer path to adminer.php
* @param array<string, string> $plugins [path next to adminer.php => content]
*/
function build_mac(string $version, string $adminer, array $plugins): void {
	global $root, $dist;
	// built by build-php.sh on GitHub's Apple Silicon runner, the linker signs it ad-hoc as arm64 requires
	$php = static_php('macos-arm64', "run build-php-github.php");

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
		"$app/Resources/php/php" => file_get_contents("$php/php"),
		"$app/Resources/adminer.php" => file_get_contents($adminer),
		"$app/Resources/launcher.php" => file_get_contents("$root/src/launcher.php"),
		"$app/Resources/server.inc.php" => file_get_contents("$root/src/server.inc.php"),
		"$app/Resources/www/index.php" => file_get_contents("$root/src/www/index.php"),
	);
	foreach ($plugins as $name => $content) {
		$files["$app/Resources/$name"] = $content;
	}
	foreach (glob("$php/license/*") as $license) {
		$files["$app/Resources/php/license/" . basename($license)] = file_get_contents($license);
	}
	$executables = array("$app/MacOS/adminer", "$app/Resources/adminer.command", "$app/Resources/php/php");
	foreach ($files as $name => $content) {
		$zip->addFromString($name, $content);
		$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9);
		// macOS' Archive Utility takes the permissions from the Unix part of the external attributes
		$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (in_array($name, $executables) ? 0100755 : 0100644) << 16);
	}
	if (!$zip->close()) {
		fail("Cannot write $filename.");
	}
	printf("%s (%d kB)\n", $filename, round(filesize($filename) / 1024));
}
