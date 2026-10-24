<?php
// the only script the server can reach: php -S falls back to it for every path but the designs in designs/, the rest of the payload is outside the docroot

// a page of another site can reach this server through DNS rebinding, its requests then carry the other site's host
if (!in_array($_SERVER["HTTP_HOST"], array("127.0.0.1:$_SERVER[SERVER_PORT]", "localhost:$_SERVER[SERVER_PORT]"), true)) {
	http_response_code(403);
	exit;
}
header("X-Adminer-Desktop: 1"); // launcher.php recognizes a running instance by it

// php -S runs scripts in the docroot, Adminer looks for adminer-plugins/ in the working directory
chdir(__DIR__ . "/..");
include "./adminer.php";
