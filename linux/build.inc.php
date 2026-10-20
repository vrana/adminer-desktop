<?php
// Linux download, included by build.php

// static-php-cli's build with pgsql, sqlite3 and pdo_mysql compiled in; Adminer uses PDO_MySQL when mysqli is missing
const LINUX_PHP = 'https://dl.static-php.dev/static-php-cli/common/';
const LINUX_ARCHS = array('x86_64', 'aarch64');

/** Build dist/adminer-VERSION-linux-ARCH.tar.gz for each architecture
* @param string $adminer path to adminer.php
* @param string $plugin path to login-ip.php
*/
function build_linux(string $version, string $adminer, string $plugin): void {
	global $root, $dist;
	$listing = json_decode(file_get_contents(cached_download(LINUX_PHP . '?format=json', true)), true);
	foreach (LINUX_ARCHS as $arch) {
		$php_version = '';
		foreach ($listing as $file) {
			if (preg_match('~^php-(' . preg_quote(PHP_BRANCH) . '\.\d+)-cli-linux-' . $arch . '\.tar\.gz$~', $file['name'], $match) && version_compare($match[1], $php_version) > 0) {
				$php_version = $match[1];
			}
		}
		if ($php_version == '') {
			fail("static-php-cli does not offer PHP " . PHP_BRANCH . " for $arch.");
		}
		$php = new PharData(cached_download(LINUX_PHP . "php-$php_version-cli-linux-$arch.tar.gz"));

		$filename = "$dist/adminer-$version-linux-$arch.tar";
		echo "Writing $filename.gz\n";
		@unlink($filename);
		@unlink("$filename.gz");
		// PharData because tar on Windows does not store the executable bit
		$tar = new PharData($filename);
		$dir = "adminer-$version";
		$files = array(
			"$dir/adminer" => file_get_contents(__DIR__ . "/adminer"),
			"$dir/php/php" => $php['php']->getContent(),
			"$dir/adminer.php" => file_get_contents($adminer),
			"$dir/adminer-plugins/login-ip.php" => file_get_contents($plugin),
			"$dir/launcher.php" => file_get_contents("$root/src/launcher.php"),
			"$dir/www/index.php" => file_get_contents("$root/src/www/index.php"),
		);
		foreach ($files as $name => $content) {
			$tar->addFromString($name, $content);
		}
		$tar["$dir/adminer"]->chmod(0755);
		$tar["$dir/php/php"]->chmod(0755);
		$tar->compress(Phar::GZ);
		unset($tar, $php); // PharData keeps the files open
		unlink($filename);
		printf("%s.gz (%d kB)\n", $filename, round(filesize("$filename.gz") / 1024));
	}
}
