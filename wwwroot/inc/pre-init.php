<?php

# This file is a part of RackTables, a datacenter and server room management
# framework. See accompanying file "COPYING" for the full copyright and
# licensing information.

/*
*
* This file is a library needed by all modules of RackTables (installer, upgrader)
* to make them able to find code and data.
*
*/

// required by exceptions.php
function isCLIMode()
{
	return ! isset ($_SERVER['REQUEST_METHOD']);
}

require_once __DIR__ . '/exceptions.php';
require_once __DIR__ . '/interface-lib.php';

// Always have default values for these options, so if a user didn't
// care to set, something would be working anyway.
$user_auth_src = 'database';
$require_local_account = TRUE;

$racktables_rootdir = realpath (dirname (__FILE__) . '/..'); // you can not override this
# Below are default values for several paths. The right way to change these
# is to add respective line(s) to secret.php, unless this is a "shared
# code, multiple instances" deploy, in which case the paths could be changed
# in the custom entry point wrapper (like own index.php)
if (! isset ($racktables_staticdir)) // the directory containing 'pix', 'js', 'css' dirs
	$racktables_staticdir = $racktables_rootdir;
if (! isset ($racktables_gwdir)) // the directory containing the 'telnet' and 'ssh' scripts
	$racktables_gwdir = realpath ($racktables_rootdir . '/../gateways');
if (! isset ($racktables_confdir)) // the directory containing secret.php (default is wwwroot/inc)
	$racktables_confdir = dirname (__FILE__);
if (! isset ($path_to_secret_php)) // you can overrride the path to secret.php separately from $racktables_confdir (legacy feature)
	$path_to_secret_php = $racktables_confdir . '/secret.php';
if (! isset ($racktables_plugins_dir)) // the directory where RT will load additional *.php files (like local.php) from
	$racktables_plugins_dir = realpath ($racktables_rootdir . '/../plugins');

// secret.php may be missing, generally it is OK
if (fileSearchExists ($path_to_secret_php))
{
	$found_secret_file = TRUE;
	// but if it exists, it must be readable, otherwise require_once fails fatally
	if (! fileSearchExists ($path_to_secret_php, 'is_readable'))
		throw new RackTablesError
		(
			"The configuration file of this RackTables instance " .
			"(<code>{$path_to_secret_php}</code>) exists, but is not readable " .
			'by the web server. Make sure the file permissions allow the web ' .
			'server to read it.',
			RackTablesError::MISCONFIGURED
		);
	require_once $path_to_secret_php;
}
else
	$found_secret_file = FALSE;

// determine local paths after loading of secret.php (maybe it has overrided racktables_plugins_dir)
if (! isset ($local_gwdir)) // the directory where RT will search gateway scripts if not found in $racktables_gwdir
	$local_gwdir = $racktables_plugins_dir . '/gateways';
if (! isset ($local_staticdir)) // the directory where RT will search static files (js/*, css/*, pix/*) if not found in $racktables_staticdir
	$local_staticdir = $racktables_plugins_dir;

// Returns the value of the MySQL driver attribute $name (e.g. 'SSL_CA'), or NULL
// if this PHP build does not have it (MAX_BUFFER_SIZE does not exist with mysqlnd).
// The PDO::MYSQL_ATTR_* constants are deprecated since PHP 8.5 in favour of
// Pdo\Mysql::ATTR_*, which do not exist before PHP 8.4.
function getPDOMySQLAttr ($name)
{
	foreach (array ("Pdo\\Mysql::ATTR_{$name}", "PDO::MYSQL_ATTR_{$name}") as $const)
		if (defined ($const))
			return constant ($const);
	return NULL;
}

// (re)connects to DB, stores PDO object in $dbxlink global var
function connectDB()
{
	global $dbxlink, $pdo_dsn, $db_username, $db_password, $pdo_bufsize, $pdo_ssl_key, $pdo_ssl_cert, $pdo_ssl_ca;
	$dbxlink = NULL;
	$drvoptions = array
	(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		// Cancel one specific SQL mode option that RackTables has been non-compliant
		// with, but which used to be off by default until MySQL 5.7. As soon as
		// respective SQL queries and table columns become compliant with those options
		// stop changing @@SQL_MODE but still keep SET NAMES in place.
		// RackTables requires the strict SQL mode, which is not enabled by default
		// in MariaDB <= 10.2.3.
		getPDOMySQLAttr ('INIT_COMMAND') => 'SET NAMES "utf8", @@SQL_MODE = CONCAT("STRICT_ALL_TABLES,", REPLACE(@@SQL_MODE, "NO_ZERO_DATE", ""))',
	);
	$optional = array
	(
		'MAX_BUFFER_SIZE' => $pdo_bufsize,
		'SSL_KEY' => $pdo_ssl_key,
		'SSL_CERT' => $pdo_ssl_cert,
		'SSL_CA' => $pdo_ssl_ca,
	);
	foreach ($optional as $name => $value)
		if (isset ($value) && NULL !== ($attr = getPDOMySQLAttr ($name)))
			$drvoptions[$attr] = $value;
	try
	{
		$dbxlink = new PDO ($pdo_dsn, $db_username, $db_password, $drvoptions);
	}
	catch (PDOException $e)
	{
		throw new RackTablesError ("Database connection failed:\n\n" . $e->getMessage(), RackTablesError::INTERNAL);
	}
}

// tries to guess the existence of the file before the php's include using the same searching method.
// in addition to calling file_exists, searches the current file's directory if the path is not looks
// like neither absolute nor relative.
// $test can be 'is_readable' to check that the file can actually be included.
function fileSearchExists ($filename, $test = 'file_exists')
{
	if (! preg_match ('@^(\.+)?/@', $filename))
	{
		$this_file_dir = dirname (__FILE__);
		if ($test ($this_file_dir . '/' . $filename))
			return TRUE;
	}
	return $test ($filename);
}
