<?php
// Linux download, included by build.php

/** Build dist/adminer-VERSION-linux-x86_64.tar.xz
* @param string $adminer path to adminer.php
* @param string $plugin path to login-ip.php
*/
function build_linux(string $version, string $adminer, string $plugin): void {
	global $root, $cache, $dist;
	// built by linux/build-php.sh with only the extensions Adminer uses
	$php_version = '';
	foreach (glob("$cache/static-php/*/php") as $filename) {
		$dir_version = basename(dirname($filename));
		if (preg_match('~^' . preg_quote(PHP_BRANCH) . '\.~', $dir_version) && version_compare($dir_version, $php_version) > 0) {
			$php_version = $dir_version;
		}
	}
	if ($php_version == '') {
		fail("Run linux/build-php.sh on Linux first, cache/static-php/ has no PHP " . PHP_BRANCH . ".");
	}
	$php = "$cache/static-php/$php_version";
	echo "Taking PHP $php_version from cache/static-php/\n";

	$filename = "$dist/adminer-$version-linux-x86_64.tar";
	echo "Writing $filename.xz\n";
	@unlink($filename);
	@unlink("$filename.xz");
	// PharData because tar on Windows does not store the executable bit
	$tar = new PharData($filename);
	$dir = "adminer-$version";
	$files = array(
		"$dir/adminer" => file_get_contents(__DIR__ . "/adminer"),
		"$dir/php/php" => file_get_contents("$php/php"),
		"$dir/adminer.php" => file_get_contents($adminer),
		"$dir/adminer-plugins/login-ip.php" => file_get_contents($plugin),
		"$dir/launcher.php" => file_get_contents("$root/src/launcher.php"),
		"$dir/www/index.php" => file_get_contents("$root/src/www/index.php"),
	);
	foreach (glob("$php/license/*") as $license) {
		$files["$dir/php/license/" . basename($license)] = file_get_contents($license);
	}
	foreach ($files as $name => $content) {
		$tar->addFromString($name, $content);
	}
	$tar["$dir/adminer"]->chmod(0755);
	$tar["$dir/php/php"]->chmod(0755);
	unset($tar); // PharData keeps the file open
	// xz makes 4.8 MB of what gzip makes 6.0 MB, PharData compresses only to gzip and bzip2
	run(array('xz', '-f', $filename));
	printf("%s.xz (%d kB)\n", $filename, round(filesize("$filename.xz") / 1024));
}
