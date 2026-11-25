<?php

// The bulk object forms report a line that fails and go on with the next
// one, and a line that fails leaves nothing behind.
class AddObjectsAtomicTest extends RTTestCase
{
	const TRIGGER = '`AddObjectsAtomicTest-fail`';
	protected static $tag_id;
	protected $saved_request, $saved_sic, $saved_pageno;

	public static function setUpBeforeClass () : void
	{
		global $taglist;
		usePreparedInsertBlade ('TagTree', array ('tag' => self::myStringStatic ('tag', __CLASS__)));
		self::$tag_id = lastInsertID();
		$taglist = addTraceToNodes (getTagList());
	}

	public static function tearDownAfterClass () : void
	{
		usePreparedDeleteBlade ('TagTree', array ('id' => self::$tag_id));
	}

	public function setUp () : void
	{
		global $sic, $pageno;
		$this->saved_request = $_REQUEST;
		$this->saved_sic = $sic;
		$this->saved_pageno = $pageno;
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $dbxlink;
		$_REQUEST = $this->saved_request;
		$sic = $this->saved_sic;
		$pageno = $this->saved_pageno;
		if (isTransactionActive())
			$dbxlink->rollBack();
		$dbxlink->exec ('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
		$result = usePreparedSelectBlade ('SELECT id FROM Object WHERE name LIKE ?', array ($this->myString ('%')));
		foreach ($result->fetchAll (PDO::FETCH_COLUMN) as $object_id)
			commitDeleteObject ($object_id);
		unset ($result);
		setMessageBuffering (FALSE);
		$GLOBALS['log_messages'] = array();
	}

	protected function setRequest ($args)
	{
		global $sic, $pageno, $log_messages;
		$pageno = 'depot';
		$_REQUEST = $sic = $args + array ('taglist' => array (self::$tag_id));
		setMessageBuffering (TRUE);
		$log_messages = array();
	}

	protected function lotRequest ($names)
	{
		$this->setRequest (array ('global_type_id' => 4, 'namelist' => implode ("\n", $names)));
	}

	protected function multipleRequest ($names)
	{
		$args = array ('num_records' => count ($names));
		foreach ($names as $i => $name)
		{
			$args["{$i}_object_type_id"] = 4;
			$args["{$i}_object_name"] = $name;
			$args["{$i}_object_label"] = '';
			$args["{$i}_object_asset_no"] = '';
		}
		$this->setRequest ($args);
	}

	// the codes of the messages shown to the user
	protected function messageCodes ()
	{
		$ret = array();
		foreach ($GLOBALS['log_messages'] as $line)
			$ret[] = $line['c'];
		return $ret;
	}

	protected function lastMessage ()
	{
		$line = end ($GLOBALS['log_messages']);
		return $line['a'][0];
	}

	protected function countRows ($table)
	{
		$result = usePreparedSelectBlade ("SELECT COUNT(*) FROM `{$table}`");
		return $result->fetchColumn();
	}

	// everything a new object brings along
	protected function counts ()
	{
		$ret = array();
		foreach (array ('Object', 'Port', 'TagStorage', 'ObjectHistory') as $table)
			$ret[$table] = $this->countRows ($table);
		return $ret;
	}

	protected function assertObjectAdded ($name)
	{
		$result = usePreparedSelectBlade ('SELECT id FROM Object WHERE name = ?', array ($name));
		$rows = $result->fetchAll (PDO::FETCH_COLUMN);
		unset ($result);
		$this->assertCount (1, $rows);
		$tags = loadEntityTags ('object', $rows[0]);
		$this->assertEquals (array (self::$tag_id), array_keys ($tags));
	}

	// Make the line with the given name fail after its object, tags and
	// ports have been added, with the error the server reports for a value
	// that is too long.
	protected function failLateFor ($name, $sqlstate = "'22001' SET MYSQL_ERRNO = 1406,")
	{
		global $dbxlink;
		try
		{
			$dbxlink->exec
			(
				'CREATE TRIGGER ' . self::TRIGGER . ' BEFORE INSERT ON `ObjectHistory` FOR EACH ROW ' .
				'IF NEW.name = ' . $dbxlink->quote ($name) . ' THEN ' .
				"SIGNAL SQLSTATE {$sqlstate} MESSAGE_TEXT = 'injected failure'; END IF"
			);
		}
		catch (PDOException $e)
		{
			$this->markTestSkipped ('cannot create the trigger: ' . $e->getMessage());
		}
	}

	public function providerForm ()
	{
		return array
		(
			array ('lotRequest', 'addLotOfObjects', 'Failed to add object'),
			array ('multipleRequest', 'addMultipleObjects', 'Error creating object'),
		);
	}

	/**
	 * @group small
	 * @dataProvider providerForm
	 */
	public function testTooLongName ($request, $handler, $error)
	{
		$long = str_repeat ('x', 300);
		$this->$request (array ($this->myString ('object 1'), $long, $this->myString ('object 2')));
		$handler();
		$this->assertEquals (array (0, 100, 0), $this->messageCodes());
		$this->assertObjectAdded ($this->myString ('object 1'));
		$this->assertObjectAdded ($this->myString ('object 2'));
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 * @dataProvider providerForm
	 */
	public function testLineFailingLate ($request, $handler, $error)
	{
		$failing_name = $this->myString ('object 2');
		$this->failLateFor ($failing_name);
		$this->$request (array ($this->myString ('object 1'), $failing_name, $this->myString ('object 3')));
		$created = count (lastCreated());
		$handler();
		$this->assertEquals (array (0, 100, 0), $this->messageCodes());
		$this->assertObjectAdded ($this->myString ('object 1'));
		$this->assertObjectAdded ($this->myString ('object 3'));
		// the failed line is gone with its tags and ports
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM Object WHERE name = ?', array ($failing_name));
		$this->assertEquals (0, $result->fetchColumn());
		unset ($result);
		// and it is not among the created entities
		$names = array();
		foreach (array_slice (lastCreated(), $created) as $item)
			if ($item['realm'] == 'object')
			{
				$object = spotEntity ('object', $item['id']);
				$names[] = $object['name'];
			}
		$this->assertEquals (array ($this->myString ('object 1'), $this->myString ('object 3')), $names);
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 * @dataProvider providerForm
	 */
	public function testOnlyLineFailingLate ($request, $handler, $error)
	{
		$failing_name = $this->myString ('object 1');
		$this->failLateFor ($failing_name);
		$this->$request (array ($failing_name));
		$before = $this->counts();
		$created = count (lastCreated());
		$handler();
		$this->assertEquals (array (100), $this->messageCodes());
		$this->assertStringContainsString ("{$error} '{$failing_name}': data too long", $this->lastMessage());
		$this->assertEquals ($before, $this->counts());
		$this->assertCount ($created, lastCreated());
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 * @dataProvider providerForm
	 */
	public function testUnexpectedError ($request, $handler, $error)
	{
		$failing_name = $this->myString ('object 1');
		// an error that is not translated
		$this->failLateFor ($failing_name, "'45000' SET");
		$this->$request (array ($failing_name));
		$before = $this->counts();
		try
		{
			$handler();
			$this->fail ('the error must not be hidden');
		}
		catch (PDOException $e)
		{
			$this->assertStringContainsString ('injected failure', $e->getMessage());
		}
		$this->assertEquals ($before, $this->counts());
		$this->assertFalse (isTransactionActive());
	}
}
