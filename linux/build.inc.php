<?php
// Linux download, included by build.php

/** Build dist/adminer-VERSION-linux-x86_64.tar.xz
* @param string $adminer path to adminer.php
* @param array<string, string> $plugins [path next to adminer.php => content]
*/
function build_linux(string $version, string $adminer, array $plugins): void {
	global $root, $dist;
	$php = static_php('linux-x86_64', "run build-php.sh on x86_64 Linux");

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
		"$dir/launcher.php" => file_get_contents("$root/src/launcher.php"),
		"$dir/server.inc.php" => file_get_contents("$root/src/server.inc.php"),
		"$dir/www/index.php" => file_get_contents("$root/src/www/index.php"),
	);
	foreach ($plugins as $name => $content) {
		$files["$dir/$name"] = $content;
	}
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
