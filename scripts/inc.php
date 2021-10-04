<?php
### Get Config
$ini_array = parse_ini_file('..\scripts\config.ini.php'); //Config file that has configurations for site.
$GLOBALS['config'] = $ini_array;