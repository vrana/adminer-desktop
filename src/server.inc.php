<?php
// The server part shared by launcher.php and app.php

const PORT = 23646; // "ADMIN" on a phone keypad, unassigned by IANA and below the ephemeral ranges; fixed so that bookmarks keep working
const PORT_TRIES = 10;

/** Find out what listens on the port
* @return 'free'|'adminer'|'other'
*/
function port_state(int $port): string {
	// a listening server accepts at once; Windows retries a refused connection for 2 seconds; a test bind would succeed even on a port in use because PHP sets SO_REUSEADDR
	$connection = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.1);
	if (!$connection) {
		return 'free';
	}
	stream_set_timeout($connection, 2);
	fwrite($connection, "HEAD /?file=favicon.ico HTTP/1.0\r\nHost: 127.0.0.1:$port\r\n\r\n");
	$response = stream_get_contents($connection);
	fclose($connection);
	return (preg_match('~^X-Adminer-Desktop:~mi', $response) ? 'adminer' : 'other');
}

function url(int $port): string {
	return "http://127.0.0.1:$port/";
}

/** Get the command running the server
* @param string $php path to the PHP binary
* @return list<string>
*/
function server_command(string $php, string $root, int $port): array {
	$command = array($php, '-n', '-d', "extension_dir=$root/php/ext");
	foreach (extensions() as $extension) {
		array_push($command, '-d', "extension=$extension");
	}
	array_push(
		$command,
		// Windows shares OPcache among processes of the same PHP build, attaching to another one's cache failed with "Opcode handlers are unusable due to ASLR"
		'-d', 'opcache.cache_id=' . getmypid(),
		'-d', 'memory_limit=-1',
		'-d', 'upload_max_filesize=1G',
		'-d', 'post_max_size=1G',
		'-d', 'max_file_uploads=100',
		'-S', "127.0.0.1:$port",
		'-t', "$root/www"
	);
	return $command;
}

/** Get the optional extensions whose runtime is installed, the others would fail to load with a warning
* @return list<string>
*/
function extensions(): array {
	if (PHP_OS_FAMILY != 'Windows') {
		return array(); // the static binary has its extensions built in
	}
	$return = array('mysqli', 'pgsql', 'sqlite3');
	$system = getenv("SystemRoot") . "/System32";
	if (file_exists("$system/msodbcsql18.dll") || file_exists("$system/msodbcsql17.dll")) {
		$return[] = 'sqlsrv';
	}
	foreach (explode(PATH_SEPARATOR, getenv("PATH")) as $dir) {
		if ($dir != "" && file_exists("$dir/oci.dll")) {
			$return[] = 'oci8';
			break;
		}
	}
	return $return;
}
