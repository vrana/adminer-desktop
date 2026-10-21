<?php
/** Start Adminer's server and open it in the browser. */

include __DIR__ . "/server.inc.php";

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

// the server logs every request, which only clutters the console
$null = (PHP_OS_FAMILY == 'Windows' ? 'NUL' : '/dev/null');
$server = proc_open(server_command(PHP_BINARY, $root, $port), array(STDIN, array('file', $null, 'w'), array('file', $null, 'w')), $pipes, $root);
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
