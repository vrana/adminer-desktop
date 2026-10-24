<?php
/** Start Adminer's server without a console and show it in an app window of Chrome, Edge, Brave or Chromium, stop the server when the window closes. */
// run by php-win.exe, which has no console; php-win.exe -S does not serve, so the server is php.exe started by CreateProcessW(CREATE_NO_WINDOW)
// the browser runs with the user's profile: a profile of its own got signed in, synced the extensions and grew to 580 MB

include __DIR__ . "/server.inc.php";

$root = __DIR__;
$windows = FFI::cdef("
	typedef struct {
		uint32_t cb; void *lpReserved; void *lpDesktop; void *lpTitle;
		uint32_t dwX, dwY, dwXSize, dwYSize, dwXCountChars, dwYCountChars, dwFillAttribute, dwFlags;
		uint16_t wShowWindow, cbReserved2; void *lpReserved2; void *hStdInput, *hStdOutput, *hStdError;
	} STARTUPINFOW;
	typedef struct { void *hProcess, *hThread; uint32_t dwProcessId, dwThreadId; } PROCESS_INFORMATION;
	int CreateProcessW(const uint16_t *application, uint16_t *command, void *process_attributes, void *thread_attributes, int inherit, uint32_t flags, void *environment, const uint16_t *directory, STARTUPINFOW *startup, PROCESS_INFORMATION *information);
	int TerminateProcess(void *process, uint32_t exit_code);
	uint32_t WaitForSingleObject(void *handle, uint32_t milliseconds);
	int CloseHandle(void *handle);
", "kernel32.dll");
// window handles are pointers, declared as intptr_t they come to PHP as integers: casting the void * of a callback crashed PHP
$user32 = FFI::cdef("
	typedef int (*WNDENUMPROC)(intptr_t window, intptr_t parameter);
	int EnumWindows(WNDENUMPROC callback, intptr_t parameter);
	int IsWindowVisible(intptr_t window);
	int GetClassNameW(intptr_t window, uint16_t *name, int length);
	int GetWindowTextW(intptr_t window, uint16_t *text, int length);
	int MessageBoxW(intptr_t window, const uint16_t *text, const uint16_t *caption, uint32_t type);
	uint32_t GetDpiForWindow(intptr_t window);
	intptr_t LoadImageW(intptr_t instance, const uint16_t *name, uint32_t type, int width, int height, uint32_t flags);
	intptr_t SendMessageW(intptr_t window, uint32_t message, intptr_t wparam, intptr_t lparam);
", "user32.dll");
$shell32 = FFI::cdef("void *ShellExecuteW(void *window, const uint16_t *operation, const uint16_t *file, const uint16_t *parameters, const uint16_t *directory, int show);", "shell32.dll");

$port = PORT;
for ($i = 0; ; $i++) {
	if ($i == PORT_TRIES) {
		fail("Ports $port to " . ($port + PORT_TRIES - 1) . " are all taken by other programs.");
	}
	$state = port_state($port + $i);
	if ($state == 'adminer') {
		// a second start opens another window, which the first one watches too
		open_window(url($port + $i));
		exit;
	}
	if ($state == 'free') {
		break;
	}
}
$port += $i;
$url = url($port);

$server = start_hidden(server_command(dirname(PHP_BINARY) . "/php.exe", $root, $port));
$deadline = microtime(true) + 10; // a time limit, not a count of tries: how long port_state() takes depends on how the OS refuses a connection
while (port_state($port) != 'adminer') {
	if (microtime(true) > $deadline || $windows->WaitForSingleObject($server->hProcess, 0) == 0) {
		$windows->TerminateProcess($server->hProcess, 0);
		fail("The server did not start on port $port.");
	}
	usleep(50000);
}

// the browser already running takes the window over and the started process ends at once, so watch the windows instead:
// the browser windows which were not there before and are titled by Adminer are ours, also those opened by a second start;
// the user's windows are excluded even when they switch to a tab with Adminer
$before = browser_windows();
if (open_window($url)) {
	$deadline = microtime(true) + 30;
	$ours = array();
	$seen = false;
	while (true) {
		usleep($seen ? 500000 : 100000); // quickly at first, the browser's icon flashes until set_icon()
		$current = browser_windows();
		foreach (array_diff_key($current, $before) as $window => $title) {
			if (preg_match('~\bAdminer\b~', $title)) {
				$ours[$window] = true;
			}
		}
		$ours = array_intersect_key($ours, $current);
		foreach ($ours as $window => $true) {
			set_icon($window);
		}
		if ($ours) {
			$seen = true;
		} elseif ($seen) {
			break;
		} elseif (microtime(true) > $deadline) {
			$windows->TerminateProcess($server->hProcess, 0);
			fail("The window of Adminer did not appear.");
		}
	}
} else {
	// without a window of our own, a message box keeps Adminer running
	$user32->MessageBoxW(0, wide("Adminer is running at $url in your browser.\nClick OK to stop it."), wide("Adminer"), 0x40);
}
$windows->TerminateProcess($server->hProcess, 0);

/** Show Adminer in an app window, without tabs and the address bar
* @return bool false if it opened in the default browser instead
*/
function open_window(string $url): bool {
	global $shell32;
	$browser = app_browser();
	if (!$browser) {
		$shell32->ShellExecuteW(null, wide("open"), wide($url), null, null, 1);
		return false;
	}
	$process = proc_open(array($browser, "--app=$url"), array(), $pipes);
	if (!$process) {
		fail("Cannot start $browser.");
	}
	return true;
}

/** Give a window icons of exactly the sizes Windows shows
* the browser gives the taskbar its favicon scaled, which blurs; the title bar is drawn by the browser from the favicon
*/
function set_icon(int $window): void {
	global $user32, $root;
	static $icons = array(); // [dpi => [ICON_SMALL, ICON_BIG]]
	$WM_GETICON = 0x7F;
	$WM_SETICON = 0x80;
	$ICON_BIG = 1;
	$dpi = $user32->GetDpiForWindow($window) ?: 96;
	if (!isset($icons[$dpi])) {
		$IMAGE_ICON = 1;
		$LR_LOADFROMFILE = 0x10;
		$file = wide("$root\\adminer.ico");
		// 16 and 24 px at 100 %; LoadImageW takes the closest size of the file
		foreach (array(16, 24) as $size) {
			$size = round($size * $dpi / 96);
			$icons[$dpi][] = $user32->LoadImageW(0, $file, $IMAGE_ICON, $size, $size, $LR_LOADFROMFILE);
		}
	}
	// the browser sets its own again after a navigation
	if ($icons[$dpi][$ICON_BIG] && $user32->SendMessageW($window, $WM_GETICON, $ICON_BIG, 0) != $icons[$dpi][$ICON_BIG]) {
		foreach ($icons[$dpi] as $type => $icon) {
			$user32->SendMessageW($window, $WM_SETICON, $type, $icon);
		}
	}
}

/** Find the visible windows of Chromium browsers, all of app_browser()
* @return string[] [window handle => title]
*/
function browser_windows(): array {
	global $user32;
	$return = array();
	$buffer = $user32->new("uint16_t[256]");
	$user32->EnumWindows(function ($window) use (&$return, $user32, $buffer) {
		if ($user32->IsWindowVisible($window) && narrow($buffer, $user32->GetClassNameW($window, $buffer, 256)) == "Chrome_WidgetWin_1") {
			$return[$window] = narrow($buffer, $user32->GetWindowTextW($window, $buffer, 256));
		}
		return 1;
	}, 0);
	return $return;
}

/** Find a browser with app windows: Chrome, Edge, which Windows has, Brave or Chromium, like launcher.php
* @return string path or '' if there is none
*/
function app_browser(): string {
	// not by the registry: reg.exe is a console program and would flash a console window
	$executables = array(
		"Google\\Chrome\\Application\\chrome.exe",
		"Microsoft\\Edge\\Application\\msedge.exe",
		"BraveSoftware\\Brave-Browser\\Application\\brave.exe",
		"Chromium\\Application\\chrome.exe",
	);
	foreach ($executables as $executable) {
		// ProgramFiles first: Chrome was 32-bit in ProgramFiles(x86), where Edge still is
		foreach (array("ProgramFiles", "ProgramFiles(x86)", "LOCALAPPDATA") as $variable) {
			if (getenv($variable) && file_exists(getenv($variable) . "\\$executable")) {
				return getenv($variable) . "\\$executable";
			}
		}
	}
	return '';
}

/** Start a program without a console window
* @param list<string> $command
* @return FFI\CData PROCESS_INFORMATION
*/
function start_hidden(array $command) {
	global $windows, $root;
	$line = implode(" ", array_map('quote_argument', $command));
	$startup = $windows->new("STARTUPINFOW");
	$startup->cb = FFI::sizeof($startup);
	$information = $windows->new("PROCESS_INFORMATION");
	$CREATE_NO_WINDOW = 0x08000000;
	if (!$windows->CreateProcessW(null, wide($line), null, null, 0, $CREATE_NO_WINDOW, null, wide($root), FFI::addr($startup), FFI::addr($information))) {
		fail("Cannot start the server.");
	}
	return $information;
}

/** Quote an argument the way Windows programs split their command line */
function quote_argument(string $argument): string {
	if ($argument != "" && !preg_match('~[\s"]~', $argument)) {
		return $argument;
	}
	// backslashes are literal except before a quote, so double those before an inner quote, which gets one more, and before the closing one
	return '"' . preg_replace_callback('~(\\\\*)("|$)~', function ($match) {
		return $match[1] . $match[1] . ($match[2] == '"' ? '\\"' : '');
	}, $argument) . '"';
}

/** Get a NUL-terminated UTF-16 string for the Windows API
* @return FFI\CData
*/
function wide(string $string) {
	global $windows;
	$utf16 = iconv('UTF-8', 'UTF-16LE', $string) . "\0\0";
	$buffer = $windows->new("uint16_t[" . (strlen($utf16) / 2) . "]");
	FFI::memcpy($buffer, $utf16, strlen($utf16));
	return $buffer;
}

/** Convert the first characters of a UTF-16 buffer to UTF-8
* @param FFI\CData $buffer
*/
function narrow($buffer, int $length): string {
	return iconv('UTF-16LE', 'UTF-8', FFI::string($buffer, 2 * $length));
}

/** @return never */
function fail(string $message) {
	global $user32;
	$user32->MessageBoxW(0, wide($message), wide("Adminer"), 0x10);
	exit(1);
}
