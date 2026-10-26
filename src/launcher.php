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
// several requests at once, e.g. Adminer's script=kill while the page it kills runs; php -S has no workers on Windows
$environment = (PHP_OS_FAMILY == 'Windows' ? null : getenv() + array("PHP_CLI_SERVER_WORKERS" => 4));
$server = proc_open(server_command(PHP_BINARY, $root, $port), array(STDIN, array('file', $null, 'w'), array('file', $null, 'w')), $pipes, $root, $environment);
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

/** Show Adminer in an app window of a Chromium browser, without tabs and the address bar, or in the default browser */
function open_browser(string $url): void {
	switch (PHP_OS_FAMILY) {
		case 'Windows':
			pclose(popen("start \"\" \"$url\"", 'r'));
			break;
		case 'Darwin':
			$browser = app_browser();
			// open passes --args only to an app it starts, so -n starts another one, which hands the window to the running browser
			exec($browser != ''
				? "open -na " . escapeshellarg($browser) . " --args " . escapeshellarg("--app=$url")
				: "open " . escapeshellarg($url)
			);
			break;
		default:
			$browser = app_browser();
			// setsid: a browser started from the terminal would end with it, also with the user's other windows
			exec(($browser != ''
				? "setsid " . escapeshellarg($browser) . " " . escapeshellarg("--app=$url")
				: "xdg-open " . escapeshellarg($url)) . " > /dev/null 2>&1 &"
			);
	}
}

/** Find a browser with app windows: the default one if it has them, otherwise Chrome, Edge, Brave or Chromium; Firefox and Safari have none
* @return string path or '' if there is none
*/
function app_browser(): string {
	$default = default_browser();
	if (PHP_OS_FAMILY == 'Darwin') {
		$names = array("Google Chrome", "Microsoft Edge", "Brave Browser", "Chromium");
		if (in_array(basename($default, ".app"), $names) && is_dir($default)) {
			return $default;
		}
		foreach ($names as $name) {
			foreach (array("/Applications", getenv("HOME") . "/Applications") as $dir) {
				if (is_dir("$dir/$name.app")) {
					return "$dir/$name.app";
				}
			}
		}
		return '';
	}
	$names = array("google-chrome", "google-chrome-stable", "microsoft-edge", "microsoft-edge-stable", "brave-browser", "brave-browser-stable", "chromium", "chromium-browser");
	if (in_array(basename($default), $names) && is_executable($default)) {
		return $default;
	}
	foreach ($names as $name) {
		$path = find_in_path($name);
		if ($path != '') {
			return $path;
		}
	}
	return '';
}

/** Find the default browser: its app on macOS, its executable on Linux
* @return string path or '' if it is unknown
*/
function default_browser(): string {
	if (PHP_OS_FAMILY == 'Darwin') {
		// NSWorkspace through JavaScript for Automation, which asks for no permission
		$script = "ObjC.import('AppKit'); $.NSWorkspace.sharedWorkspace.URLForApplicationToOpenURL($.NSURL.URLWithString('https://www.adminer.org/')).path.js";
		return trim((string) shell_exec("osascript -l JavaScript -e " . escapeshellarg($script) . " 2>/dev/null"));
	}
	$desktop = trim((string) shell_exec("xdg-settings get default-web-browser 2>/dev/null"));
	if (!preg_match('~^[\w.-]+\.desktop$~', $desktop)) {
		return '';
	}
	$data_dirs = explode(":", getenv("XDG_DATA_DIRS") ?: "/usr/local/share:/usr/share");
	foreach (array_merge(array(getenv("XDG_DATA_HOME") ?: getenv("HOME") . "/.local/share"), $data_dirs) as $dir) {
		$file = @file_get_contents("$dir/applications/$desktop");
		if ($file && preg_match('~^Exec=(.*)~m', $file, $match)) {
			// the first word which is not env and its variables, e.g. Exec=env BAMF_DESKTOP_FILE_HINT=... /snap/bin/chromium %U
			foreach (preg_split('~\s+~', trim($match[1])) as $word) {
				if ($word != 'env' && !preg_match('~^\w+=~', $word)) {
					return ($word[0] == '/' ? $word : find_in_path($word));
				}
			}
		}
	}
	return '';
}

/** Find an executable in PATH
* @return string path or '' if there is none
*/
function find_in_path(string $name): string {
	foreach (explode(":", getenv("PATH")) as $dir) {
		if ($dir != "" && is_executable("$dir/$name")) {
			return "$dir/$name";
		}
	}
	return '';
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
