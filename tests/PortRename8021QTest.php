<?php

// The 802.1Q configuration of a port is stored by object ID and port name, so
// renaming the port must move the configuration to the new name, and a rename
// that does not take place must not change the configuration. Deleting the
// port must delete the configuration as well.
class PortRename8021QTest extends RTTestCase
{
	protected $objects = array();
	protected $vdom_id = NULL;
	protected $vst_id = NULL;
	protected $trigger = NULL;
	protected $hidden_table = NULL;
	protected $saved_hook = NULL;
	protected $saved_hooks_stack = NULL;
	protected static $hook_calls = array();
	protected static $hook_error = NULL;
	protected $cached_conf = array ('mode' => 'trunk', 'allowed' => array (1, 5, 7), 'native' => 5);
	protected $desired_conf = array ('mode' => 'trunk', 'allowed' => array (1, 7), 'native' => 7);

	public function setUp () : void
	{
		global $hook, $hooks_stack;
		$this->saved_hook = $hook;
		$this->saved_hooks_stack = $hooks_stack;
		self::$hook_calls = array();
		self::$hook_error = NULL;
		usePreparedInsertBlade ('VLANDomain', array ('description' => $this->myString ('domain')));
		$this->vdom_id = lastInsertID();
		foreach (array (VLAN_DFL_ID, 5, 7) as $vlan_id)
			usePreparedInsertBlade
			(
				'VLANDescription',
				array ('domain_id' => $this->vdom_id, 'vlan_id' => $vlan_id, 'vlan_type' => 'ondemand', 'vlan_descr' => "vlan {$vlan_id}")
			);
		usePreparedInsertBlade
		(
			'VLANSwitchTemplate',
			array ('description' => $this->myString ('template'), 'mutex_rev' => 0, 'saved_by' => '')
		);
		$this->vst_id = lastInsertID();
		usePreparedInsertBlade
		(
			'VLANSTRule',
			array ('vst_id' => $this->vst_id, 'rule_no' => 100, 'port_pcre' => '/./', 'port_role' => 'anymode', 'wrt_vlans' => '', 'description' => '')
		);
	}

	public function tearDown () : void
	{
		global $dbxlink, $hook, $hooks_stack, $sic, $pageno, $tabno, $log_messages;
		foreach (array ('object_id', 'port_id') as $argname)
			unset ($sic[$argname], $_REQUEST[$argname]);
		$pageno = 'index';
		$tabno = 'default';
		$log_messages = array();
		$hook = $this->saved_hook;
		$hooks_stack = $this->saved_hooks_stack;
		if ($this->hidden_table !== NULL)
		{
			// the test failed half-way, bring the session and the table back
			$dbxlink->exec ('UNLOCK TABLES');
			$dbxlink->exec ('SET autocommit = 1');
			$dbxlink->exec ("RENAME TABLE {$this->hidden_table} TO VLANValidID");
		}
		if ($this->trigger !== NULL)
			$dbxlink->exec ("DROP TRIGGER IF EXISTS {$this->trigger}");
		foreach ($this->objects as $object_id)
		{
			usePreparedDeleteBlade ('VLANSwitch', array ('object_id' => $object_id));
			commitDeleteObject ($object_id);
		}
		usePreparedDeleteBlade ('VLANSTRule', array ('vst_id' => $this->vst_id));
		usePreparedDeleteBlade ('VLANSwitchTemplate', array ('id' => $this->vst_id));
		usePreparedDeleteBlade ('VLANDescription', array ('domain_id' => $this->vdom_id));
		usePreparedDeleteBlade ('VLANDomain', array ('id' => $this->vdom_id));
	}

	protected function addSwitch ($in_domain = TRUE)
	{
		$object_id = commitAddObject ($this->myString ('switch ' . count ($this->objects)), NULL, 8, NULL);
		$this->objects[] = $object_id;
		if ($in_domain)
			usePreparedInsertBlade
			(
				'VLANSwitch',
				array ('object_id' => $object_id, 'domain_id' => $this->vdom_id, 'template_id' => $this->vst_id)
			);
		return $object_id;
	}

	// Add a port with a cached configuration and a different desired one, as
	// after a local edit that has not been deployed yet.
	protected function addConfiguredPort ($object_id, $port_name, $port_type = '1-24')
	{
		$port_id = commitAddPort ($object_id, $port_name, $port_type, '', '');
		add8021QPort ($object_id, $port_name, $this->cached_conf);
		upd8021QPort ('desired', $object_id, $port_name, $this->desired_conf, $this->cached_conf);
		return $port_id;
	}

	protected function stored ($object_id)
	{
		$ret = array();
		foreach (array ('desired', 'cached') as $instance)
		{
			$ret[$instance] = getStored8021QConfig ($object_id, $instance);
			ksort ($ret[$instance]);
			foreach (array_keys ($ret[$instance]) as $pn)
				sort ($ret[$instance][$pn]['allowed']);
		}
		return $ret;
	}

	protected function expected ($port_names)
	{
		$ret = array ('desired' => array(), 'cached' => array());
		foreach ($port_names as $pn)
		{
			$ret['desired'][$pn] = $this->desired_conf;
			$ret['cached'][$pn] = $this->cached_conf;
		}
		ksort ($ret['desired']);
		ksort ($ret['cached']);
		return $ret;
	}

	protected function portName ($port_id)
	{
		$result = usePreparedSelectBlade ('SELECT name FROM Port WHERE id = ?', array ($port_id));
		return $result->fetchColumn();
	}

	public function providerL2Address ()
	{
		return array
		(
			array (''), // no table locks
			array ('00:11:22:33:44:55'), // the table locks for the L2 address check
		);
	}

	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testRenameMovesConfig ($l2address)
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$this->addConfiguredPort ($object_id, 'gi0/2');
		commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', $l2address, '');
		$this->assertEquals ('ge-0/0/1', $this->portName ($port_id));
		$this->assertEquals ($this->expected (array ('ge-0/0/1', 'gi0/2')), $this->stored ($object_id));
		$this->assertFalse (isTransactionActive());
	}

	// The next sync must find the renamed port as it was before the rename,
	// not as a port gone from the switch plus a new one.
	/**
	 * @group small
	 */
	public function testRenameKeepsSyncState ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', '', '');
		$plan = get8021QSyncOptions
		(
			getVLANSwitchInfo ($object_id),
			getStored8021QConfig ($object_id, 'desired'),
			getStored8021QConfig ($object_id, 'cached'),
			array ('ge-0/0/1' => $this->cached_conf)
		);
		$this->assertEquals (array ('ge-0/0/1'), array_keys ($plan));
		$this->assertEquals ('ok_to_push', $plan['ge-0/0/1']['status']);
	}

	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testRenameWithoutConfig ($l2address)
	{
		foreach (array (TRUE, FALSE) as $in_domain)
		{
			$object_id = $this->addSwitch ($in_domain);
			$port_id = commitAddPort ($object_id, 'gi0/1', '1-24', '', '');
			commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', $l2address, '');
			$this->assertEquals ('ge-0/0/1', $this->portName ($port_id));
			$this->assertEquals (array ('desired' => array(), 'cached' => array()), $this->stored ($object_id));
			// free the L2 address for the next object
			commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', '', '');
		}
	}

	/**
	 * @group small
	 */
	public function testRenameNotInVLANDomain ()
	{
		$object_id = $this->addSwitch (FALSE);
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', '', '');
		$this->assertEquals ($this->expected (array ('ge-0/0/1')), $this->stored ($object_id));
	}

	// Changing other port properties must not touch the configuration.
	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testUpdateWithoutRename ($l2address)
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		commitUpdatePort ($object_id, $port_id, 'gi0/1', '1-24', 'label', $l2address, 'comment');
		$this->assertEquals ($this->expected (array ('gi0/1')), $this->stored ($object_id));
	}

	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testRenameToDuplicateName ($l2address)
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$this->addConfiguredPort ($object_id, 'gi0/2');
		$before = $this->stored ($object_id);
		try
		{
			commitUpdatePort ($object_id, $port_id, 'gi0/2', '1-24', '', $l2address, '');
			$this->fail ('the rename must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		$this->assertEquals ('gi0/1', $this->portName ($port_id));
		$this->assertEquals ($before, $this->stored ($object_id));
		$this->assertFalse (isTransactionActive());
	}

	// The configuration stored under the new name (here left behind by an
	// earlier rename) is not overwritten, and nothing is moved.
	/**
	 * @group small
	 */
	public function testRenameToNameWithConfig ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$orphan = array ('mode' => 'access', 'allowed' => array (5), 'native' => 5);
		add8021QPort ($object_id, 'ge-0/0/1', $orphan);
		commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', '', '');
		$this->assertEquals ('ge-0/0/1', $this->portName ($port_id));
		$expected = $this->expected (array ('gi0/1'));
		$expected['desired']['ge-0/0/1'] = $orphan;
		$expected['cached']['ge-0/0/1'] = $orphan;
		ksort ($expected['desired']);
		ksort ($expected['cached']);
		$this->assertEquals ($expected, $this->stored ($object_id));
	}

	// Two ports of different types may have the same name and then share the
	// configuration, which therefore stays with the name.
	/**
	 * @group small
	 */
	public function testRenameSharedName ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		commitAddPort ($object_id, 'gi0/1', '1-19', '', '');
		commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', '', '');
		$this->assertEquals ($this->expected (array ('gi0/1')), $this->stored ($object_id));

		// the other way round: the new name belongs to another port already
		$port2_id = $this->addConfiguredPort ($object_id, 'gi0/2');
		commitUpdatePort ($object_id, $port2_id, 'ge-0/0/1', '1-19', '', '', '');
		$this->assertEquals ($this->expected (array ('gi0/1', 'gi0/2')), $this->stored ($object_id));
	}

	// Two ports swap names through a temporary name, each configuration
	// follows its port.
	/**
	 * @group small
	 */
	public function testSwapNames ()
	{
		$object_id = $this->addSwitch();
		$port1_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$port2_id = commitAddPort ($object_id, 'gi0/2', '1-24', '', '');
		$conf2 = array ('mode' => 'access', 'allowed' => array (5), 'native' => 5);
		add8021QPort ($object_id, 'gi0/2', $conf2);
		commitUpdatePort ($object_id, $port1_id, 'tmp', '1-24', '', '', '');
		commitUpdatePort ($object_id, $port2_id, 'gi0/1', '1-24', '', '', '');
		commitUpdatePort ($object_id, $port1_id, 'gi0/2', '1-24', '', '', '');
		$expected = $this->expected (array ('gi0/2'));
		$expected['desired']['gi0/1'] = $conf2;
		$expected['cached']['gi0/1'] = $conf2;
		ksort ($expected['desired']);
		ksort ($expected['cached']);
		$this->assertEquals ($expected, $this->stored ($object_id));
	}

	// number of rows in all six 802.1Q tables with exactly this spelling
	protected function countSpelling ($object_id, $port_name)
	{
		$ret = 0;
		foreach (array ('PortVLANMode', 'PortAllowedVLAN', 'PortNativeVLAN', 'CachedPVM', 'CachedPAV', 'CachedPNV') as $table)
		{
			$result = usePreparedSelectBlade
			(
				"SELECT COUNT(*) FROM {$table} WHERE object_id = ? AND BINARY port_name = ?",
				array ($object_id, $port_name)
			);
			$ret += $result->fetchColumn();
			unset ($result);
		}
		return $ret;
	}

	// A rename that differs only in letter case keeps the configuration, now
	// under the new spelling (the collation of the columns ignores case, but
	// the sync compares the names as PHP strings).
	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testRenameLetterCase ($l2address)
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$this->addConfiguredPort ($object_id, 'gi0/2');
		// a port with the first temporary name tried, so the next one is used
		commitAddPort ($object_id, "rename-{$port_id}-0", '1-24', '', '');
		commitUpdatePort ($object_id, $port_id, 'Gi0/1', '1-24', '', $l2address, '');
		$this->assertEquals ('Gi0/1', $this->portName ($port_id));
		$this->assertEquals ($this->expected (array ('Gi0/1', 'gi0/2')), $this->stored ($object_id));
		$this->assertEquals (0, $this->countSpelling ($object_id, 'gi0/1'));
		// cached: mode, 3 allowed, native; desired: mode, 2 allowed, native
		$this->assertEquals (9, $this->countSpelling ($object_id, 'Gi0/1'));
		$plan = get8021QSyncOptions
		(
			getVLANSwitchInfo ($object_id),
			getStored8021QConfig ($object_id, 'desired'),
			getStored8021QConfig ($object_id, 'cached'),
			array ('Gi0/1' => $this->cached_conf, 'gi0/2' => $this->cached_conf)
		);
		ksort ($plan);
		$this->assertEquals (array ('Gi0/1', 'gi0/2'), array_keys ($plan));
		$this->assertEquals ('ok_to_push', $plan['Gi0/1']['status']);
		$this->assertFalse (isTransactionActive());
	}

	// Another port with the same name in other letter case shares the
	// configuration, so it stays as it is.
	/**
	 * @group small
	 */
	public function testRenameLetterCaseSharedName ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		commitAddPort ($object_id, 'GI0/1', '1-19', '', '');
		commitUpdatePort ($object_id, $port_id, 'Gi0/1', '1-24', '', '', '');
		$this->assertEquals ('Gi0/1', $this->portName ($port_id));
		$this->assertEquals ($this->expected (array ('gi0/1')), $this->stored ($object_id));
	}

	// If moving the configuration fails, the rename does not take place either.
	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testRenameFailingInMove ($l2address)
	{
		global $dbxlink;
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$this->trigger = 'PortRename8021QTest_' . getmypid();
		try
		{
			$dbxlink->exec
			(
				"CREATE TRIGGER {$this->trigger} BEFORE INSERT ON PortVLANMode FOR EACH ROW " .
				"BEGIN IF NEW.port_name = 'fail' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected'; END IF; END"
			);
		}
		catch (PDOException $e)
		{
			$this->trigger = NULL;
			$this->markTestSkipped ('cannot create a trigger: ' . $e->getMessage());
		}
		$before = $this->stored ($object_id);
		try
		{
			commitUpdatePort ($object_id, $port_id, 'fail', '1-24', '', $l2address, '');
			$this->fail ('the rename must fail');
		}
		catch (PDOException $e)
		{
			$this->assertStringContainsString ('injected', $e->getMessage());
		}
		$this->assertEquals ('gi0/1', $this->portName ($port_id));
		$this->assertEquals ($before, $this->stored ($object_id));
		$this->assertFalse (isTransactionActive());
		$this->assertSessionReleased ($object_id);
	}

	// autocommit is on again and no table is locked: a write to a table that
	// was not in the lock list succeeds and stays after a ROLLBACK
	protected function assertSessionReleased ($object_id)
	{
		global $dbxlink;
		$result = usePreparedSelectBlade ('SELECT @@autocommit');
		$this->assertEquals (1, $result->fetchColumn());
		unset ($result);
		$comment = $this->myString ('comment');
		usePreparedUpdateBlade ('Object', array ('comment' => $comment), array ('id' => $object_id));
		$dbxlink->exec ('ROLLBACK');
		$result = usePreparedSelectBlade ('SELECT comment FROM Object WHERE id = ?', array ($object_id));
		$this->assertEquals ($comment, $result->fetchColumn());
	}

	// If the table locks cannot be taken (here one of the tables is missing,
	// as it would be for a missing privilege), the session must be released
	// and the error must reach the caller.
	/**
	 * @group small
	 */
	public function testRenameFailingToLock ()
	{
		global $dbxlink;
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$before = $this->stored ($object_id);
		$this->hidden_table = 'VLANValidID_' . getmypid();
		$dbxlink->exec ("RENAME TABLE VLANValidID TO {$this->hidden_table}");
		try
		{
			commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', '00:11:22:33:44:55', '');
			$this->fail ('the rename must fail');
		}
		catch (PDOException $e)
		{
			$this->assertStringContainsString ('VLANValidID', $e->getMessage());
		}
		$this->assertSessionReleased ($object_id);
		$dbxlink->exec ("RENAME TABLE {$this->hidden_table} TO VLANValidID");
		$this->hidden_table = NULL;
		$this->assertEquals ('gi0/1', $this->portName ($port_id));
		$this->assertEquals ($before, $this->stored ($object_id));
		$this->assertFalse (isTransactionActive());
	}

	// A portConfChanged hook that records what it sees: a table outside the
	// lock list (Object) and, through a second connection, what is committed.
	public static function recordHook ($object_id, $port_name, $port)
	{
		global $pdo_dsn, $db_username, $db_password;
		$result = usePreparedSelectBlade ('SELECT name FROM Object WHERE id = ?', array ($object_id));
		$object_name = $result->fetchColumn();
		unset ($result);
		$other = new PDO ($pdo_dsn, $db_username, $db_password);
		$stmt = $other->prepare ('SELECT port_name FROM PortVLANMode WHERE object_id = ? ORDER BY port_name');
		$stmt->execute (array ($object_id));
		self::$hook_calls[] = array
		(
			'port_name' => $port_name,
			'port' => $port,
			'object_found' => $object_name !== FALSE,
			'committed' => $stmt->fetchAll (PDO::FETCH_COLUMN),
		);
		if (self::$hook_error !== NULL)
			throw new RackTablesError (self::$hook_error);
	}

	protected function expectedHookCalls ($old_name, $new_name, $committed)
	{
		return array
		(
			array ('port_name' => $old_name, 'port' => NULL, 'object_found' => TRUE, 'committed' => $committed),
			array ('port_name' => $new_name, 'port' => $this->desired_conf, 'object_found' => TRUE, 'committed' => $committed),
		);
	}

	protected function mutexRev ($object_id)
	{
		$vswitch = getVLANSwitchInfo ($object_id);
		return $vswitch['mutex_rev'];
	}

	// The hooks run once per affected name, after the rename is committed and
	// the tables are unlocked, and a 802.1Q form opened before is outdated.
	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testRenameHooks ($l2address)
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$this->addConfiguredPort ($object_id, 'gi0/2');
		registerHook ('portConfChanged', 'PortRename8021QTest::recordHook');
		$mutex_rev = $this->mutexRev ($object_id);
		commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', $l2address, '');
		$this->assertEquals ($this->expectedHookCalls ('gi0/1', 'ge-0/0/1', array ('ge-0/0/1', 'gi0/2')), self::$hook_calls);
		$this->assertEquals ($mutex_rev + 1, $this->mutexRev ($object_id));

		// no hooks and no new revision when the configuration does not move
		self::$hook_calls = array();
		commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', 'label', $l2address, '');
		$this->assertEquals (array(), self::$hook_calls);
		$this->assertEquals ($mutex_rev + 1, $this->mutexRev ($object_id));
	}

	// A failing hook does not undo the committed rename, its error reaches
	// the caller after all hooks have run.
	/**
	 * @group small
	 * @dataProvider providerL2Address
	 */
	public function testRenameFailingHook ($l2address)
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		registerHook ('portConfChanged', 'PortRename8021QTest::recordHook');
		self::$hook_error = 'hook failed';
		try
		{
			commitUpdatePort ($object_id, $port_id, 'ge-0/0/1', '1-24', '', $l2address, '');
			$this->fail ('the hook error must reach the caller');
		}
		catch (RackTablesError $e)
		{
			$this->assertEquals ('hook failed', $e->getMessage());
		}
		$this->assertEquals ($this->expectedHookCalls ('gi0/1', 'ge-0/0/1', array ('ge-0/0/1')), self::$hook_calls);
		$this->assertEquals ('ge-0/0/1', $this->portName ($port_id));
		$this->assertEquals ($this->expected (array ('ge-0/0/1')), $this->stored ($object_id));
		$this->assertFalse (isTransactionActive());
		$this->assertSessionReleased ($object_id);
	}
	// run the ophandler the way index.php?module=redirect does
	protected function runPortOp ($opname, $object_id, $args = array())
	{
		global $sic, $ophandler, $pageno, $tabno, $op;
		$pageno = 'object';
		$tabno = 'ports';
		$op = $opname;
		foreach (array ('object_id' => $object_id) + $args as $argname => $argvalue)
			$sic[$argname] = $_REQUEST[$argname] = $argvalue;
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function hookedNames ()
	{
		$ret = array();
		foreach (self::$hook_calls as $call)
		{
			$this->assertNull ($call['port']);
			$ret[] = $call['port_name'];
		}
		sort ($ret);
		return $ret;
	}

	/**
	 * @group small
	 */
	public function testDeletePort ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$this->addConfiguredPort ($object_id, 'gi0/2');
		registerHook ('portConfChanged', 'PortRename8021QTest::recordHook');
		$mutex_rev = $this->mutexRev ($object_id);
		$this->runPortOp ('delPort', $object_id, array ('port_id' => $port_id));
		$this->assertFalse ($this->portName ($port_id));
		$this->assertEquals ($this->expected (array ('gi0/2')), $this->stored ($object_id));
		$this->assertEquals (array ('gi0/1'), $this->hookedNames());
		// the hooks run after the commit
		$this->assertEquals (array ('gi0/2'), self::$hook_calls[0]['committed']);
		$this->assertEquals ($mutex_rev + 1, $this->mutexRev ($object_id));
		$this->assertFalse (isTransactionActive());
	}

	// The configuration goes, and the hook gets, the spelling of the name
	// the configuration is stored under, which may differ from the port name
	// in letter case.
	/**
	 * @group small
	 */
	public function testDeletePortLetterCase ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		usePreparedUpdateBlade ('Port', array ('name' => 'GI0/1'), array ('id' => $port_id));
		registerHook ('portConfChanged', 'PortRename8021QTest::recordHook');
		$this->runPortOp ('delPort', $object_id, array ('port_id' => $port_id));
		$this->assertFalse ($this->portName ($port_id));
		$this->assertEquals ($this->expected (array()), $this->stored ($object_id));
		$this->assertEquals (0, $this->countSpelling ($object_id, 'gi0/1'));
		$this->assertEquals (array ('gi0/1'), $this->hookedNames());
	}

	// A failing hook does not undo the committed delete, the other hooks
	// still run and the first error reaches the caller.
	/**
	 * @group small
	 */
	public function testDeletePortFailingHook ()
	{
		$object_id = $this->addSwitch();
		$this->addConfiguredPort ($object_id, 'gi0/1');
		$this->addConfiguredPort ($object_id, 'gi0/2');
		registerHook ('portConfChanged', 'PortRename8021QTest::recordHook');
		self::$hook_error = 'hook failed';
		try
		{
			$this->runPortOp ('deleteAll', $object_id);
			$this->fail ('the hook error must reach the caller');
		}
		catch (RackTablesError $e)
		{
			$this->assertEquals ('hook failed', $e->getMessage());
		}
		$this->assertEquals (array ('gi0/1', 'gi0/2'), $this->hookedNames());
		foreach (self::$hook_calls as $call)
			$this->assertEquals (array(), $call['committed']);
		$this->assertEquals (0, count (getObjectPortsAndLinksTerse ($object_id)));
		$this->assertEquals ($this->expected (array()), $this->stored ($object_id));
		$this->assertFalse (isTransactionActive());
	}

	// The configuration stays with a name another port of the object still has.
	/**
	 * @group small
	 */
	public function testDeletePortSharedName ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$port2_id = commitAddPort ($object_id, 'GI0/1', '1-19', '', '');
		$this->runPortOp ('delPort', $object_id, array ('port_id' => $port_id));
		$this->assertFalse ($this->portName ($port_id));
		$this->assertEquals ($this->expected (array ('gi0/1')), $this->stored ($object_id));
		$this->runPortOp ('delPort', $object_id, array ('port_id' => $port2_id));
		$this->assertEquals ($this->expected (array()), $this->stored ($object_id));
	}

	// Configuration stored under a name no port has is not touched.
	/**
	 * @group small
	 */
	public function testDeleteAllPorts ()
	{
		$object_id = $this->addSwitch();
		$port_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$this->addConfiguredPort ($object_id, 'gi0/2');
		commitAddPort ($object_id, 'gi0/2', '1-19', '', '');
		commitAddPort ($object_id, 'gi0/3', '1-24', '', '');
		$orphan = array ('mode' => 'access', 'allowed' => array (5), 'native' => 5);
		add8021QPort ($object_id, 'ge-0/0/1', $orphan);
		registerHook ('portConfChanged', 'PortRename8021QTest::recordHook');
		$this->runPortOp ('deleteAll', $object_id);
		$this->assertEquals (0, count (getObjectPortsAndLinksTerse ($object_id)));
		$expected = $this->expected (array());
		$expected['desired']['ge-0/0/1'] = $orphan;
		$expected['cached']['ge-0/0/1'] = $orphan;
		$this->assertEquals ($expected, $this->stored ($object_id));
		$this->assertEquals (array ('gi0/1', 'gi0/2'), $this->hookedNames());
		$this->assertFalse (isTransactionActive());
	}

	// If the configuration cannot be deleted, the port is not deleted either.
	/**
	 * @group small
	 */
	public function testDeletePortFailing ()
	{
		global $dbxlink;
		$object_id = $this->addSwitch();
		$port1_id = $this->addConfiguredPort ($object_id, 'gi0/1');
		$port2_id = $this->addConfiguredPort ($object_id, 'gi0/2');
		$this->trigger = 'PortRename8021QTest_' . getmypid();
		try
		{
			$dbxlink->exec
			(
				"CREATE TRIGGER {$this->trigger} BEFORE DELETE ON CachedPVM FOR EACH ROW " .
				"BEGIN IF OLD.port_name = 'gi0/2' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected'; END IF; END"
			);
		}
		catch (PDOException $e)
		{
			$this->trigger = NULL;
			$this->markTestSkipped ('cannot create a trigger: ' . $e->getMessage());
		}
		registerHook ('portConfChanged', 'PortRename8021QTest::recordHook');
		$before = $this->stored ($object_id);
		foreach (array (array ('delPort', array ('port_id' => $port2_id)), array ('deleteAll', array())) as $test)
		{
			try
			{
				$this->runPortOp ($test[0], $object_id, $test[1]);
				$this->fail ('the delete must fail');
			}
			catch (PDOException $e)
			{
				$this->assertStringContainsString ('injected', $e->getMessage());
			}
			$this->assertEquals ('gi0/1', $this->portName ($port1_id));
			$this->assertEquals ('gi0/2', $this->portName ($port2_id));
			$this->assertEquals ($before, $this->stored ($object_id));
			$this->assertEquals (array(), self::$hook_calls, 'no hook must run');
			$this->assertFalse (isTransactionActive());
		}
	}
}
