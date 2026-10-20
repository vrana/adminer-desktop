#!/bin/sh
# Start Adminer and open it in the browser
cd "$(dirname "$0")" && exec php/php -n launcher.php
