<?php
// PHPUnit may include this file in a function scope (separate test processes),
// make sure dictionary.php defines the global variable.
global $dictionary;
require_once '../wwwroot/inc/dictionary.php';
require_once '../wwwroot/inc/upgrade.php';

// The 'data' directory contains an SQL dump file for each version, including both schema and sample data.
// Developers should generate a dump file as part of the release process.  Instructions are in the wiki.
// For each version, this test will drop & re-create the database, import the dump with the mysql
// command-line client, then upgrade it to the current version the way the web upgrader does.
//
// Each dump runs in a separate process: the web upgrader runs once per request, and the
// dictionary batch (reloadDictionary()) cannot run twice in one PHP process. The unit test
// database is restored to a clean install after the last dump and after any dump that
// did not upgrade cleanly (see testUpgrade()).
class UpgradeTest extends RTTestCase
{
	// Definitions that an upgraded database must share with a fresh install, as
	// (table, start of the line in SHOW CREATE TABLE). Older releases created the
	// two keys as non-unique, see issue #20.
	private static $fresh_install_lines = array
	(
		array ('LDAPCache', 'UNIQUE KEY `presented_username` '),
		array ('Object', 'UNIQUE KEY `id-tid` '),
		array ('TagTree', 'UNIQUE KEY `id-is_assignable` '),
		array ('AttributeValue', 'CONSTRAINT `AttributeValue-FK-object` '),
		array ('TagStorage', 'CONSTRAINT `TagStorage-FK-TagTree` '),
	);

	private static $admin_account = FALSE;
	// TRUE in a separate test process (testUpgrade() has started there).
	private static $in_test_process = FALSE;
	// TRUE while the unit test database holds something other than a clean install
	// or a dump upgraded without any failure.
	private static $db_dirty = FALSE;

	public static function setUpBeforeClass () : void
	{
		global $dbxlink;
		// Remember the administrator account to restore it with the clean install.
		$result = $dbxlink->query ('SELECT * FROM UserAccount WHERE user_id = 1');
		self::$admin_account = $result->fetch (PDO::FETCH_ASSOC);
		$result->closeCursor();
	}

	// Every dump replaces the unit test database, the tests after this class expect a
	// clean install. PHPUnit 9 also calls this method in each separate test process,
	// which cleans up after itself in testUpgrade(), so act in the main process only.
	public static function tearDownAfterClass () : void
	{
		if (! self::$in_test_process && self::findMySQLClient() !== NULL)
			self::restoreCleanInstall();
	}

	public static function dumpProvider ()
	{
		$versions = array();
		foreach (scandir ('./data') as $file)
			if (preg_match ('/^(\d+\.\d+\.\d+(-community)?)\.sql$/', $file, $m))
				$versions[] = $m[1];
		usort ($versions, 'version_compare');
		$ret = array();
		foreach ($versions as $version)
			$ret[$version] = array ($version);
		return $ret;
	}

	/**
	 * @group large
	 * @dataProvider dumpProvider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testUpgrade ($version)
	{
		$mysql_bin = self::findMySQLClient();
		if ($mysql_bin === NULL)
			$this->markTestSkipped ('The mysql (or mariadb) command-line client is not in PATH, it is required to import tests/data/*.sql.');

		// The bootstrap of the next test process needs a usable database. Leave a dump
		// that was upgraded without any failure as it is (that is the common case and
		// saves a reinstall), restore a clean install after anything else: a failed
		// import, an exception, a failed or incomplete assertion (catch) or a fatal
		// error (shutdown function, which also retries a restore that failed here).
		self::$in_test_process = TRUE;
		register_shutdown_function (array (__CLASS__, 'restoreIfDirty'));
		try
		{
			self::$db_dirty = TRUE;
			$this->upgradeDump ($mysql_bin, $version);
			self::$db_dirty = FALSE;
		}
		catch (Throwable $e)
		{
			// Report the original failure, with a failed restore added to its message.
			try
			{
				self::restoreIfDirty();
			}
			catch (Throwable $restore_error)
			{
				$prop = new ReflectionProperty ($e instanceof Exception ? 'Exception' : 'Error', 'message');
				if (PHP_VERSION_ID < 80100) // no effect and deprecated since then
					$prop->setAccessible (TRUE);
				$prop->setValue ($e, $e->getMessage() . "\n\nIn addition: " . $restore_error->getMessage());
			}
			throw $e;
		}
	}

	public static function restoreIfDirty ()
	{
		if (! self::$db_dirty)
			return;
		self::restoreCleanInstall();
		// only after a successful restore, so that the shutdown function retries otherwise
		self::$db_dirty = FALSE;
	}

	// Replaces the unit test database with a clean install and the remembered account
	// with user_id 1. The installation runs in a new PHP process: reloadDictionary()
	// cannot run twice in one process, and this process may be past a fatal error.
	private static function restoreCleanInstall ()
	{
		$code = <<<'ENDOFCODE'
require_once '../wwwroot/inc/pre-init.php';
require_once '../wwwroot/inc/dictionary.php';
require_once '../wwwroot/inc/config.php';
require_once '../wwwroot/inc/install.php';
connectDB();
if (! preg_match ('/\bdbname=([^;]+)/', $pdo_dsn, $m) || stristr ($m[1], '_unittest') === FALSE)
	die ("refusing to reinstall a database without _unittest in its name\n");
$db_name = $m[1];
$dbxlink->exec ("DROP DATABASE IF EXISTS {$db_name}");
$dbxlink->exec ("CREATE DATABASE {$db_name} CHARACTER SET utf8 COLLATE utf8_general_ci");
ob_start();
$ok = init_database_static();
$output = ob_get_clean();
if ($ok !== TRUE || FALSE !== strpos ($output, 'queries failed'))
	die (strip_tags ($output) . "\n");
echo "OK\n";
ENDOFCODE;
		$cmd = 'cd ' . escapeshellarg (__DIR__) . ' && ' . escapeshellarg (PHP_BINARY) . ' -r ' . escapeshellarg ($code) . ' 2>&1';
		exec ($cmd, $output, $exitcode);
		if ($exitcode != 0 || end ($output) !== 'OK')
			throw new RuntimeException ("Restoring a clean install of the unit test database failed (exit code {$exitcode}):\n" . implode ("\n", $output));
		connectDB();
		if (self::$admin_account !== FALSE)
			usePreparedInsertBlade ('UserAccount', self::$admin_account);
		// The new database reuses the IDs of entities that earlier tests cached.
		global $entityCache;
		$entityCache = array();
	}

	private function upgradeDump ($mysql_bin, $version)
	{
		global $dbxlink, $dbver;

		$restrict_fk = self::serverRestrictsFKOnNonUniqueKeys();
		$db_name = getDBName();
		$dbxlink->exec ("DROP DATABASE {$db_name}");
		$dbxlink->exec ("CREATE DATABASE {$db_name} CHARACTER SET utf8 COLLATE utf8_general_ci");
		list ($exitcode, $output) = self::importDump ($mysql_bin, "./data/{$version}.sql", $restrict_fk);
		$this->assertEquals (0, $exitcode, "Populating the {$version} database failed: {$output}");

		// the DB was deleted & re-created, so the current connection is invalid; re-connect
		connectDB();
		// The upgrader authenticates the administrator on the old schema (the dumps
		// have the password "admin" as an SHA-1 hash in a char(40) column).
		$this->assertTrue (authenticate_admin ('admin', 'admin'), "upgrader login before upgrading from {$version}");
		$this->assertFalse (authenticate_admin ('admin', 'wrong password'));
		// a cache row of an earlier release, with an SHA-1 digest of the password
		usePreparedInsertBlade ('LDAPCache', array ('presented_username' => 'upgradetest', 'successful_hash' => sha1 ('secret')));
		// some batches depend on the version the upgrade started from
		$dbver = $version;
		$path = getDBUpgradePath ($version, CODE_VERSION);
		$this->assertNotNull ($path, "Empty upgrade path when upgrading from {$version}");

		// Run the whole path and the dictionary reload before asserting, as the web
		// upgrader does, so that the report lists the failures of all batches.
		$failures = array();
		foreach (array_merge ($path, array ('dictionary')) as $batchid)
		{
			ob_start();
			executeUpgradeBatch ($batchid);
			$output = ob_get_clean();
			if (FALSE !== strpos ($output, 'queries failed'))
				$failures[$batchid] = self::failedQueries ($output);
		}
		$report = '';
		foreach ($failures as $batchid => $list)
			$report .= "batch {$batchid}:\n" . implode ("\n", $list) . "\n";

		$this->assertSame ('', $report, "Upgrading from {$version} failed:\n{$report}");

		// Cheap structural checks of the result.
		$this->assertEquals (CODE_VERSION, getDatabaseVersion(), "DB_VERSION after upgrading from {$version}");
		global $dictionary;
		$result = $dbxlink->query ('SELECT COUNT(*) FROM Dictionary WHERE dict_key BETWEEN 1 AND 49999');
		$this->assertEquals (count ($dictionary), $result->fetchColumn(), "Stock dictionary records after upgrading from {$version}");
		$result->closeCursor();
		// The password hash column holds password_hash() results (issue #25), the
		// legacy hash is kept, and the upgrader authenticates with either form.
		$result = usePreparedSelectBlade
		(
			'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			array ($db_name, 'UserAccount', 'user_password_hash')
		);
		$row = $result->fetch (PDO::FETCH_NUM);
		$result->closeCursor();
		$this->assertSame ('varchar(255)', $row[0], "UserAccount.user_password_hash type after upgrading from {$version}");
		$this->assertSame ('YES', $row[1], "UserAccount.user_password_hash nullability after upgrading from {$version}");
		$this->assertContains ($row[2], array (NULL, 'NULL'), "UserAccount.user_password_hash default after upgrading from {$version}");
		$this->assertSame (sha1 ('admin'), spotEntity ('user', 1, TRUE)['user_password_hash'], "administrator password hash after upgrading from {$version}");
		$this->assertTrue (authenticate_admin ('admin', 'admin'), "upgrader login after upgrading from {$version}");
		usePreparedUpdateBlade ('UserAccount', array ('user_password_hash' => makePasswordHash ('admin')), array ('user_id' => 1));
		$this->assertTrue (authenticate_admin ('admin', 'admin'), "upgrader login with a new hash after upgrading from {$version}");
		$this->assertFalse (authenticate_admin ('admin', 'wrong password'));
		foreach (self::$fresh_install_lines as $item)
		{
			list ($table, $prefix) = $item;
			$expected = self::findLine (self::freshInstallTableSQL ($table), $prefix);
			$this->assertNotNull ($expected, "{$prefix}in the install.php definition of {$table}");
			$this->assertSame
			(
				$expected,
				self::findLine (self::showCreateTable ($table), $prefix),
				"Definition of {$table} after upgrading from {$version}"
			);
		}
		// The LDAP cache is emptied and has room for password_hash() results (issue #131).
		$result = usePreparedSelectBlade
		(
			'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			array ($db_name, 'LDAPCache', 'successful_hash')
		);
		$row = $result->fetch (PDO::FETCH_NUM);
		$result->closeCursor();
		$this->assertSame ('varchar(255)', $row[0], "LDAPCache.successful_hash type after upgrading from {$version}");
		$this->assertSame ('NO', $row[1], "LDAPCache.successful_hash nullability after upgrading from {$version}");
		// the key that included the hash is gone, as in a fresh install
		$this->assertNull (self::findLine (self::freshInstallTableSQL ('LDAPCache'), 'KEY `scanidx` '));
		$this->assertNull (self::findLine (self::showCreateTable ('LDAPCache'), 'KEY `scanidx` '), "LDAPCache.scanidx after upgrading from {$version}");
		$result = $dbxlink->query ('SELECT COUNT(*) FROM LDAPCache');
		$this->assertEquals (0, $result->fetchColumn(), "LDAPCache rows after upgrading from {$version}");
		$result->closeCursor();
		// MySQL 8.4 refuses to rebuild a table or to restore a dump in which a foreign key
		// references anything but the full column list of a PRIMARY or UNIQUE key.
		$this->assertSame (array(), self::foreignKeysOnNonUniqueKeys(), "Foreign keys onto non-unique keys after upgrading from {$version}");
		$this->assertViewsAsInstalled ($db_name, $version);
	}

	// Returns the line that starts with $prefix from a CREATE TABLE statement,
	// without the separating comma, or NULL.
	private static function findLine ($create_sql, $prefix)
	{
		foreach (explode ("\n", $create_sql) as $line)
		{
			$line = rtrim (trim ($line), ',');
			if (0 === strpos ($line, $prefix))
				return $line;
		}
		return NULL;
	}

	private static function freshInstallTableSQL ($table)
	{
		require_once '../wwwroot/inc/install.php';
		foreach (get_pseudo_file ('structure') as $sql)
			if (preg_match ('/^\s*CREATE TABLE `' . preg_quote ($table, '/') . '` \(/', $sql))
				return $sql;
		throw new RuntimeException ("Table {$table} is not in the install.php schema");
	}

	private static function showCreateTable ($table)
	{
		global $dbxlink;
		$result = $dbxlink->query ("SHOW CREATE TABLE `{$table}`");
		$row = $result->fetch (PDO::FETCH_NUM);
		$result->closeCursor();
		return $row[1];
	}

	// Returns "table.constraint" for each foreign key whose referenced columns are not
	// exactly the columns of a PRIMARY or UNIQUE key of the referenced table.
	private static function foreignKeysOnNonUniqueKeys ()
	{
		$result = usePreparedSelectBlade
		(
			'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS ' .
			'WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
		);
		$unique = array();
		foreach ($result->fetchAll (PDO::FETCH_ASSOC) as $row)
			$unique[$row['TABLE_NAME']][$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
		$result->closeCursor();
		$result = usePreparedSelectBlade
		(
			'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE ' .
			'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, POSITION_IN_UNIQUE_CONSTRAINT'
		);
		$fks = array();
		foreach ($result->fetchAll (PDO::FETCH_ASSOC) as $row)
		{
			$name = "{$row['TABLE_NAME']}.{$row['CONSTRAINT_NAME']}";
			$fks[$name]['table'] = $row['REFERENCED_TABLE_NAME'];
			$fks[$name]['columns'][] = $row['REFERENCED_COLUMN_NAME'];
		}
		$result->closeCursor();
		$ret = array();
		foreach ($fks as $name => $fk)
			if (! isset ($unique[$fk['table']]) || ! in_array ($fk['columns'], $unique[$fk['table']], TRUE))
				$ret[] = $name;
		return $ret;
	}

	// Every view must have the definition a fresh install creates (the 0.23.0-community
	// batch redefines Rack, issue #23). Create each view of install.php under another
	// name and compare the definitions as the server stores them.
	private function assertViewsAsInstalled ($db_name, $version)
	{
		global $dbxlink;
		require_once '../wwwroot/inc/install.php';
		$checked = 0;
		foreach (get_pseudo_file ('structure') as $statement)
		{
			if (! preg_match ('/^CREATE VIEW `([^`]+)` AS /', $statement, $m))
				continue;
			$view = $m[1];
			$copy = "{$view}_install_check";
			$dbxlink->exec ("DROP VIEW IF EXISTS `{$copy}`");
			$dbxlink->exec (preg_replace ('/^CREATE VIEW `[^`]+`/', "CREATE VIEW `{$copy}`", $statement));
			try
			{
				$result = usePreparedSelectBlade
				(
					'SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?)',
					array ($db_name, $view, $copy)
				);
				$defs = $result->fetchAll (PDO::FETCH_KEY_PAIR);
				$result->closeCursor();
			}
			finally
			{
				$dbxlink->exec ("DROP VIEW `{$copy}`");
			}
			$this->assertArrayHasKey ($view, $defs, "View {$view} after upgrading from {$version}");
			$this->assertSame ($defs[$copy], $defs[$view], "Definition of view {$view} after upgrading from {$version}");
			$checked++;
		}
		$this->assertSame (4, $checked, 'views in install.php');
	}

	private static function findMySQLClient ()
	{
		foreach (explode (PATH_SEPARATOR, (string) getenv ('PATH')) as $dir)
			foreach (array ('mysql', 'mariadb') as $name)
				if ($dir != '' && is_file ("{$dir}/{$name}") && is_executable ("{$dir}/{$name}"))
					return "{$dir}/{$name}";
		return NULL;
	}

	// TRUE when the server (by default) refuses foreign keys that reference a
	// non-unique key, which is MySQL 8.4 and later.
	private static function serverRestrictsFKOnNonUniqueKeys ()
	{
		global $dbxlink;
		$result = $dbxlink->query ("SHOW GLOBAL VARIABLES LIKE 'restrict_fk_on_non_standard_key'");
		$row = $result->fetch (PDO::FETCH_NUM);
		$result->closeCursor();
		return $row !== FALSE && strtoupper ($row[1]) == 'ON';
	}

	// Import a dump into the unit test database with the mysql client and return
	// array (exit code, client output). The dump is adapted on the fly the way an
	// operator restoring an old backup on a current server would have to.
	private static function importDump ($mysql_bin, $filename, $restrict_fk)
	{
		global $pdo_dsn, $db_username, $db_password;
		$sql = file_get_contents ($filename);
		// The triggers in the dumps of 0.20.12 to 0.21.1 were dumped with
		// NO_AUTO_CREATE_USER in their sql_mode. MySQL 8.0 removed that mode and
		// rejects it, it has no effect on the trigger bodies.
		$sql = preg_replace_callback
		(
			"/^\\/\\*!50003 SET sql_mode *= '[^']*' \\*\\/ ?;/m",
			function ($m) { return preg_replace ('/,?NO_AUTO_CREATE_USER/', '', $m[0]); },
			$sql
		);
		// The triggers and views are dumped with DEFINER=`racktables`@`localhost`.
		// Importing them as any other user requires SUPER or SET_ANY_DEFINER, so let
		// them belong to the importing user, as a fresh install does.
		$sql = preg_replace ('/(\\/\\*!500(13|17) )DEFINER=`[^`]*`@`[^`]*` ?/', '\\1', $sql);
		// All dumps reference the non-unique keys Object.id-tid and TagTree.id-is_assignable
		// from foreign keys, which MySQL 8.4 refuses by default (error 6125, issue #20).
		// Relax this for the import session only, the upgrade runs with the server default.
		if ($restrict_fk)
			$sql = "SET SESSION restrict_fk_on_non_standard_key = OFF;\n" . $sql;

		// Pass the credentials in a private option file, not on the command line.
		$options = array ('user' => $db_username, 'password' => $db_password);
		foreach (explode (';', preg_replace ('/^mysql:/', '', $pdo_dsn)) as $pair)
		{
			$kv = explode ('=', $pair, 2);
			if (count ($kv) != 2)
				continue;
			if ($kv[0] == 'host' || $kv[0] == 'port')
				$options[$kv[0]] = $kv[1];
			elseif ($kv[0] == 'unix_socket')
				$options['socket'] = $kv[1];
		}
		$cnf = "[client]\n";
		foreach ($options as $name => $value)
			$cnf .= $name . '="' . addcslashes ($value, '"\\') . "\"\n";

		$cnf_file = tempnam (sys_get_temp_dir(), 'rtcnf');
		$sql_file = tempnam (sys_get_temp_dir(), 'rtsql');
		try
		{
			chmod ($cnf_file, 0600);
			file_put_contents ($cnf_file, $cnf);
			file_put_contents ($sql_file, $sql);
			$cmd = escapeshellarg ($mysql_bin) . ' --defaults-extra-file=' . escapeshellarg ($cnf_file) .
				' ' . escapeshellarg (getDBName()) . ' < ' . escapeshellarg ($sql_file) . ' 2>&1';
			exec ($cmd, $output, $exitcode);
		}
		finally
		{
			unlink ($cnf_file);
			unlink ($sql_file);
		}
		return array ($exitcode, implode ("\n", $output));
	}

	// Turns the output of executeUpgradeBatch() into a list of "query -- error" strings.
	private static function failedQueries ($output)
	{
		if (! preg_match ('/queries failed:.*?<pre>(.*)<\\/pre>/s', $output, $m))
			return array ($output);
		$ret = array();
		$entry = '';
		foreach (explode ("\n", rtrim ($m[1], "\n")) as $line)
		{
			$entry .= ($entry == '' ? '' : "\n") . $line;
			// executeUpgradeBatch() ends each entry with " -- " and the error message
			if (preg_match ('/ -- .*$/', $line))
			{
				$ret[] = $entry;
				$entry = '';
			}
		}
		if ($entry != '')
			$ret[] = $entry;
		return $ret;
	}
}
