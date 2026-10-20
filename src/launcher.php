<?php
/** Start Adminer's server and open it in the browser. */

const PORT = 23646; // "ADMIN" on a phone keypad, unassigned by IANA and below the ephemeral ranges; fixed so that bookmarks keep working
const PORT_TRIES = 10;

$root = __DIR__;
$port = PORT;
if (function_exists('cli_set_process_title')) {
	@cli_set_process_title("Adminer"); // the console window title on Windows
}

for ($i = 0; ; $i++) {
	if ($i == PORT_TRIES) {
		fail("Ports $port to " . ($port + PORT_TRIES - 1) . " are all taken.");
	}
	$state = port_state($port + $i);
	if ($state == 'adminer') {
		$url = url($port + $i);
		echo "Adminer is already running at $url\n";
		open_browser($url);
		exit;
	}
	if ($state == 'free') {
		break;
	}
	echo "Port " . ($port + $i) . " is taken by another program.\n";
}
$port += $i;
$url = url($port);

$command = array(PHP_BINARY, '-n', '-d', "extension_dir=$root/php/ext");
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
// the server logs every request, which only clutters the console
$null = (PHP_OS_FAMILY == 'Windows' ? 'NUL' : '/dev/null');
$server = proc_open($command, array(STDIN, array('file', $null, 'w'), array('file', $null, 'w')), $pipes, $root);
if (!$server) {
	fail("Cannot start the server.");
}
$deadline = microtime(true) + 10; // a time limit, not a count of tries: how long port_state() takes depends on how the OS refuses a connection
while (port_state($port) != 'adminer') {
	if (microtime(true) > $deadline || !proc_get_status($server)['running']) {
		proc_terminate($server);
		fail("The server did not start on port $port.");
	}
	usleep(50000);
}

echo "Adminer is running at $url\n";
open_browser($url);
echo "Press Enter or close this window to stop it.\n";
fgets(STDIN);
proc_terminate($server);

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

function open_browser(string $url): void {
	switch (PHP_OS_FAMILY) {
		case 'Windows':
			pclose(popen("start \"\" \"$url\"", 'r'));
			break;
		case 'Darwin':
			exec("open " . escapeshellarg($url));
			break;
		default:
			exec("xdg-open " . escapeshellarg($url) . " > /dev/null 2>&1 &");
	}
}

/** @return never */
function fail(string $message) {
	fwrite(STDERR, "$message\n");
	if (PHP_OS_FAMILY == 'Windows') {
		echo "Press Enter to close this window.\n";
		fgets(STDIN);
	}
	exit(1);
}
