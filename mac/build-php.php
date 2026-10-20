<?php
// Build the static PHP for macOS on GitHub's Apple Silicon runner and download it into cache/static-php/macos-arm64/, which build.php packs.
// Needs gh signed in. The runner builds what is pushed: .github/workflows/build-php.yml and build-php.sh.

const REPOSITORY = 'vrana/adminer-desktop';
const WORKFLOW = 'build-php.yml';
const ARTIFACT = 'php-macos-arm64';

$target = dirname(__DIR__) . "/cache/static-php/macos-arm64";
$start = time();
gh("workflow run " . WORKFLOW . " --repo " . REPOSITORY);

// gh workflow run prints no id of the run it started, so find the newest run started since
echo "Looking for the run\n";
$run = null;
for ($i = 0; !$run; $i++) {
	if ($i == 30) {
		fail("The run did not appear, see https://github.com/" . REPOSITORY . "/actions");
	}
	sleep(2);
	$runs = json_decode(shell_exec("gh run list --repo " . REPOSITORY . " --workflow " . WORKFLOW . " --event workflow_dispatch --limit 5 --json databaseId,createdAt,url"), true);
	foreach ($runs ?: array() as $candidate) {
		if (strtotime($candidate['createdAt']) >= $start - 10) {
			$run = $candidate;
			break;
		}
	}
}
echo "Waiting for $run[url]\n";
gh("run watch $run[databaseId] --repo " . REPOSITORY . " --exit-status");

// gh run download does not overwrite, so unpack aside and move each version into place
$download = "$target.download";
remove_recursive($download);
gh("run download $run[databaseId] --repo " . REPOSITORY . " --name " . ARTIFACT . " --dir " . escapeshellarg($download));
if (!is_dir($target) && !mkdir($target, 0755, true)) {
	fail("Cannot create $target.");
}
foreach (glob("$download/*", GLOB_ONLYDIR) as $dir) {
	remove_recursive("$target/" . basename($dir));
	if (!rename($dir, "$target/" . basename($dir))) {
		fail("Cannot move $dir to $target.");
	}
	echo "$target/" . basename($dir) . "\n";
}
remove_recursive($download);

function gh(string $arguments): void {
	passthru("gh $arguments", $status);
	if ($status) {
		fail("gh $arguments failed.");
	}
}

function remove_recursive(string $path): void {
	if (!file_exists($path)) {
		return;
	}
	foreach (scandir($path) as $file) {
		if ($file === '.' || $file === '..') {
			continue;
		}
		$full = "$path/$file";
		is_dir($full) ? remove_recursive($full) : unlink($full);
	}
	rmdir($path);
}

/** @return never */
function fail(string $message) {
	fwrite(STDERR, "$message\n");
	exit(1);
}
