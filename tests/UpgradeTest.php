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
	// Dumps whose upgrade path still has failing statements on servers that refuse foreign
	// keys on non-unique keys (restrict_fk_on_non_standard_key=ON, the default since
	// MySQL 8.4), see issue #20. These combinations are reported as incomplete as long as
	// every failed statement matches one of $issue20_failures ("statement -- error"), and
	// fail on any other error.
	private static $issue20_versions = array
	(
		'0.20.7', '0.20.8', '0.20.9', '0.20.10', '0.20.11', '0.20.12', '0.20.13', '0.20.14',
		'0.21.0', '0.21.1',
	);
	// TagTree is referenced through its non-unique key id-is_assignable, which the server
	// refuses to rebuild; the second error is a consequence of the first.
	private static $issue20_failures = array
	(
		"/^ALTER TABLE TagTree ADD COLUMN (color|description) [^\\n]* -- Cannot drop index '<unknown key name>': needed in a foreign key constraint$/",
		"/^ALTER TABLE TagTree ADD COLUMN description [^\\n]* -- Unknown column 'color' in 'TagTree'$/",
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
			if (preg_match ('/^(\d+\.\d+\.\d+)\.sql$/', $file, $m))
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
		// some batches depend on the version the upgrade started from
		$dbver = $version;
		$path = getDBUpgradePath ($version, CODE_VERSION);
		$this->assertNotNull ($path, "Empty upgrade path when upgrading from {$version}");

		// Run the whole path and the dictionary reload before asserting, as the web
		// upgrader does, so that a known failure in one batch does not hide an unknown
		// one in a later batch.
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

		if (count ($failures) && $restrict_fk && in_array ($version, self::$issue20_versions))
		{
			$unknown = array();
			foreach ($failures as $list)
				foreach ($list as $entry)
					if (! self::matchesAny ($entry, self::$issue20_failures))
						$unknown[] = $entry;
			$this->assertEmpty ($unknown, "Upgrading from {$version} failed with errors other than the known ones of issue #20:\n{$report}");
			$this->markTestIncomplete ("Upgrading from {$version} with restrict_fk_on_non_standard_key=ON fails as known from issue #20:\n{$report}");
		}
		$this->assertSame ('', $report, "Upgrading from {$version} failed:\n{$report}");

		// Cheap structural checks of the result.
		$this->assertEquals (CODE_VERSION, getDatabaseVersion(), "DB_VERSION after upgrading from {$version}");
		global $dictionary;
		$result = $dbxlink->query ('SELECT COUNT(*) FROM Dictionary WHERE dict_key BETWEEN 1 AND 49999');
		$this->assertEquals (count ($dictionary), $result->fetchColumn(), "Stock dictionary records after upgrading from {$version}");
		$result->closeCursor();
		// The 0.20.7 batch makes these keys UNIQUE as in a fresh install. Databases past
		// 0.20.7 keep whatever they had, so check only the upgrade paths through 0.20.7.
		if (in_array ('0.20.7', $path))
			foreach (array ('Object' => 'id-tid', 'TagTree' => 'id-is_assignable') as $table => $key)
			{
				$result = usePreparedSelectBlade
				(
					'SELECT MAX(NON_UNIQUE) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
					array ($db_name, $table, $key)
				);
				$this->assertSame ('0', (string) $result->fetchColumn(), "{$table}.{$key} must be a UNIQUE key after upgrading from {$version}");
				$result->closeCursor();
			}
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

	private static function matchesAny ($text, $patterns)
	{
		foreach ($patterns as $pattern)
			if (preg_match ($pattern, $text))
				return TRUE;
		return FALSE;
	}
}
