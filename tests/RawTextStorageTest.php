<?php
require_once '../wwwroot/inc/solutions.php';
require_once '../wwwroot/inc/interface-config.php';
require_once '../wwwroot/inc/slb-interface.php';

// The forms store text as it was typed, and the edit forms show it so that
// the browser posts back exactly the same text (issue #73). A request is set
// up the way transformRequestData() leaves it: the HTML-escaped values in
// $_REQUEST and the typed ones in $sic.
class RawTextStorageTest extends RTTestCase
{
	const TEXT = "a&b <c> \"d\" 'e'";
	// TEXT without spaces, for the forms that split their input into words
	const WORD = "a&b<c>\"d\"'e'";

	protected $saved_request, $saved_server, $saved_sic, $saved_auto_tags, $saved_pageno, $saved_tabno, $saved_username;
	protected $object_ids = array();
	protected $object_id;

	public function setUp () : void
	{
		global $sic, $auto_tags, $pageno, $tabno, $remote_username;
		$this->saved_request = $_REQUEST;
		$this->saved_server = $_SERVER;
		$this->saved_sic = $sic;
		$this->saved_auto_tags = $auto_tags;
		$this->saved_pageno = $pageno;
		$this->saved_tabno = $tabno;
		$this->saved_username = $remote_username;
		// The default RackCode allows everything to the admin account.
		$auto_tags[] = array ('tag' => '$userid_1');
		@session_start();
		$this->object_id = $this->object_ids[] = commitAddObject ($this->myString ('server'), NULL, 4, NULL);
	}

	public function tearDown () : void
	{
		global $sic, $auto_tags, $pageno, $tabno, $remote_username, $log_messages, $dbxlink;
		setMessageBuffering (FALSE);
		$log_messages = array();
		$_REQUEST = $this->saved_request;
		$_SERVER = $this->saved_server;
		$sic = $this->saved_sic;
		$auto_tags = $this->saved_auto_tags;
		$pageno = $this->saved_pageno;
		$tabno = $this->saved_tabno;
		$remote_username = $this->saved_username;
		if (isTransactionActive())
			$dbxlink->rollBack();
		// Whatever a test created carries the name of this class.
		$result = usePreparedSelectBlade ('SELECT id FROM Object WHERE name LIKE ? ORDER BY id DESC', array ('%' . $this->myString ('') . '%'));
		$ids = $result->fetchAll (PDO::FETCH_COLUMN);
		unset ($result);
		foreach (array_unique (array_merge (array_reverse ($this->object_ids), $ids)) as $object_id)
		{
			usePreparedDeleteBlade ('IPv4NAT', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('IPv4LB', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('IPv4Allocation', array ('object_id' => $object_id));
			try
			{
				commitDeleteObject ($object_id);
			}
			catch (Exception $e)
			{
			}
		}
		$this->object_ids = array();
		global $entityCache;
		$entityCache = array();
	}

	// A name that is unique to this test class and contains the special characters.
	protected function text ($what)
	{
		return self::TEXT . ' ' . $this->myString ($what);
	}

	protected function word ($what)
	{
		return self::WORD . $this->myString ($what);
	}

	protected function setRequest ($pageno_, $tabno_, $args)
	{
		global $sic, $pageno, $tabno, $log_messages, $entityCache;
		$pageno = $pageno_;
		$tabno = $tabno_;
		$sic = $args;
		$_REQUEST = array();
		foreach ($args as $key => $value)
			$_REQUEST[$key] = is_array ($value) ? $value : htmlspecialchars ($value, ENT_QUOTES, 'UTF-8');
		$_SERVER['REQUEST_URI'] = '/index.php?page=' . $pageno_ . '&tab=' . $tabno_;
		setMessageBuffering (TRUE);
		$log_messages = array();
		$entityCache = array();
	}

	protected function objectRow ($object_id)
	{
		$result = usePreparedSelectBlade ('SELECT name, label, asset_no, comment FROM Object WHERE id = ?', array ($object_id));
		$row = $result->fetch (PDO::FETCH_ASSOC);
		unset ($result);
		return $row;
	}

	protected function objectIDByName ($name)
	{
		$result = usePreparedSelectBlade ('SELECT id FROM Object WHERE name = ?', array ($name));
		$ret = $result->fetchColumn();
		unset ($result);
		$this->assertNotFalse ($ret, "an object named as typed: {$name}");
		$this->object_ids[] = $ret;
		return $ret;
	}

	protected function column ($query, $args)
	{
		$result = usePreparedSelectBlade ($query, $args);
		$ret = $result->fetchAll (PDO::FETCH_COLUMN);
		unset ($result);
		return $ret;
	}

	// The messages of the handlers must not carry the typed text as HTML.
	protected function assertMessagesEscaped ()
	{
		global $log_messages;
		foreach ($log_messages as $message)
			$this->assertStringNotContainsString ('<c>', var_export ($message, TRUE));
	}

	protected function updateObjectArgs ($values)
	{
		return array
		(
			'object_id' => $this->object_id,
			'taglist' => array(),
			'num_attrs' => '0',
			'object_type_id' => '4',
			'object_name' => $values['name'],
			'object_label' => $values['label'],
			'object_asset_no' => $values['asset_no'],
			'object_comment' => $values['comment'],
		);
	}

	/**
	 * @group small
	 */
	public function testUpdateObjectAndEditForm ()
	{
		$values = array
		(
			'name' => $this->text ('name'),
			'label' => $this->text ('label'),
			'asset_no' => $this->text ('asset'),
			'comment' => $this->text ("comment\nsecond line"),
		);
		$this->setRequest ('object', 'edit', $this->updateObjectArgs ($values));
		updateObject();
		$this->assertSame ($values, $this->objectRow ($this->object_id));
		$this->assertMessagesEscaped();

		// The form shows the text escaped once, which the browser turns back
		// into the stored text.
		$this->setRequest ('object', 'edit', array ('object_id' => $this->object_id));
		$form = getOutputOf ('renderEditObjectForm');
		foreach (array ('name' => 'object_name', 'label' => 'object_label', 'asset_no' => 'object_asset_no') as $key => $input_name)
			$this->assertStringContainsString
			(
				"name={$input_name} value='" . htmlspecialchars ($values[$key], ENT_QUOTES, 'UTF-8') . "'>",
				$form
			);
		$this->assertStringContainsString ('>' . htmlspecialchars ($values['comment'], ENT_QUOTES, 'UTF-8') . '</textarea>', $form);
		$this->assertStringNotContainsString ('<c>', $form);

		// Saving what the form shows changes nothing.
		$this->setRequest ('object', 'edit', $this->updateObjectArgs ($values));
		updateObject();
		$this->assertSame ($values, $this->objectRow ($this->object_id));
	}

	/**
	 * @group small
	 */
	public function testBulkObjectForms ()
	{
		$name = $this->text ('multi');
		$this->setRequest
		(
			'depot',
			'addmore',
			array
			(
				'taglist' => array(),
				'num_records' => '1',
				'0_object_type_id' => '4',
				'0_object_name' => $name,
				'0_object_label' => $this->text ('multi label'),
				'0_object_asset_no' => $this->text ('multi asset'),
			)
		);
		addMultipleObjects();
		$row = $this->objectRow ($this->objectIDByName ($name));
		$this->assertSame ($this->text ('multi label'), $row['label']);
		$this->assertSame ($this->text ('multi asset'), $row['asset_no']);
		$this->assertMessagesEscaped();

		$names = array ($this->text ('lot 1'), $this->text ('lot 2'));
		$this->setRequest ('depot', 'addmore', array ('taglist' => array(), 'global_type_id' => '4', 'namelist' => implode ("\r\n", $names)));
		addLotOfObjects();
		foreach ($names as $each)
			$this->objectIDByName ($each);
		$this->assertMessagesEscaped();

		// A name that is taken is reported without becoming HTML. (The text of
		// the exception that follows it is escaped only outside of the CLI mode.)
		$this->setRequest ('depot', 'addmore', array ('taglist' => array(), 'global_type_id' => '4', 'namelist' => $names[0]));
		addLotOfObjects();
		global $log_messages;
		$this->assertCount (1, $log_messages);
		$this->assertStringStartsWith
		(
			"Failed to add object '" . htmlspecialchars ($names[0], ENT_QUOTES, 'UTF-8') . "': ",
			$log_messages[0]['a'][0]
		);
	}

	/**
	 * getLocationTrail(), which the rack form calls, remembers the locations
	 * of its first call in the process.
	 *
	 * @group small
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLocationRowRack ()
	{
		// location
		$name = $this->text ('location');
		$this->setRequest ('rackspace', 'editlocations', array ('name' => $name, 'parent_id' => '0'));
		addLocation();
		$location_id = $this->objectIDByName ($name);
		$this->assertMessagesEscaped();
		$name = $this->text ('location renamed');
		$this->setRequest ('rackspace', 'editlocations', array ('location_id' => $location_id, 'name' => $name, 'parent_id' => '0'));
		updateLocation();
		$this->assertSame ($name, $this->objectRow ($location_id)['name']);
		$comment = $this->text ('location comment');
		$args = array
		(
			'location_id' => $location_id,
			'name' => $name,
			'parent_id' => '0',
			'comment' => $comment,
			'taglist' => array(),
			'num_attrs' => '0',
		);
		$this->setRequest ('location', 'edit', $args);
		updateLocation();
		$row = $this->objectRow ($location_id);
		$this->assertSame ($name, $row['name']);
		$this->assertSame ($comment, $row['comment']);
		$this->assertMessagesEscaped();
		$this->setRequest ('location', 'edit', array ('location_id' => $location_id));
		$form = getOutputOf ('renderEditLocationForm', $location_id);
		$this->assertStringContainsString ("name=name value='" . htmlspecialchars ($name, ENT_QUOTES, 'UTF-8') . "'>", $form);
		$this->assertStringContainsString ('>' . htmlspecialchars ($comment, ENT_QUOTES, 'UTF-8') . '</textarea>', $form);
		$this->assertStringNotContainsString ('<c>', $form);

		// row
		$row_name = $this->text ('row');
		$this->setRequest ('rackspace', 'editrows', array ('name' => $row_name, 'location_id' => (string) $location_id));
		addRow();
		$row_id = $this->objectIDByName ($row_name);
		$this->assertMessagesEscaped();
		$row_name = $this->text ('row renamed');
		$this->setRequest ('rackspace', 'editrows', array ('row_id' => $row_id, 'name' => $row_name, 'location_id' => (string) $location_id));
		updateRow();
		$this->assertSame ($row_name, $this->objectRow ($row_id)['name']);
		$this->assertMessagesEscaped();
		$this->setRequest ('row', 'editrow', array ('row_id' => $row_id));
		$form = getOutputOf ('renderEditRowForm', $row_id);
		$this->assertStringContainsString ("name=name value='" . htmlspecialchars ($row_name, ENT_QUOTES, 'UTF-8') . "'>", $form);

		// racks
		$rack_name = $this->text ('rack');
		$rack_asset = $this->text ('rack asset');
		$this->setRequest
		(
			'row',
			'newrack',
			array ('row_id' => $row_id, 'taglist' => array(), 'mode' => 'one', 'name' => $rack_name, 'height1' => '10', 'asset_no' => $rack_asset)
		);
		addRack();
		$racks = $this->column ('SELECT id FROM Object WHERE objtype_id = 1560 AND name = ? AND asset_no = ?', array ($rack_name, $rack_asset));
		$this->assertCount (1, $racks);
		$rack_id = $this->object_ids[] = $racks[0];
		$this->assertMessagesEscaped();
		$many = array ($this->text ('rack m1'), $this->text ('rack m2'));
		$this->setRequest
		(
			'row',
			'newrack',
			array ('row_id' => $row_id, 'taglist' => array(), 'mode' => 'many', 'names' => implode ("\n", $many), 'height2' => '10')
		);
		addRack();
		foreach ($many as $each)
			$this->objectIDByName ($each);
		$this->assertMessagesEscaped();

		$rack_values = array
		(
			'name' => $this->text ('rack renamed'),
			'label' => NULL,
			'asset_no' => $this->text ('rack asset 2'),
			'comment' => $this->text ('rack comment'),
		);
		$args = array
		(
			'rack_id' => $rack_id,
			'row_id' => (string) $row_id,
			'name' => $rack_values['name'],
			'height' => '10',
			'asset_no' => $rack_values['asset_no'],
			'comment' => $rack_values['comment'],
			'taglist' => array(),
			'num_attrs' => '0',
		);
		$this->setRequest ('rack', 'edit', $args);
		updateRack();
		$this->assertSame ($rack_values, $this->objectRow ($rack_id));
		$this->assertMessagesEscaped();
		$this->setRequest ('rack', 'edit', array ('rack_id' => $rack_id));
		$form = getOutputOf ('renderEditRackForm', $rack_id);
		$this->assertStringContainsString ("name=name value='" . htmlspecialchars ($rack_values['name'], ENT_QUOTES, 'UTF-8') . "'>", $form);
		$this->assertStringContainsString ("name=asset_no value='" . htmlspecialchars ($rack_values['asset_no'], ENT_QUOTES, 'UTF-8') . "'>", $form);
		$this->assertStringContainsString ('>' . htmlspecialchars ($rack_values['comment'], ENT_QUOTES, 'UTF-8') . '</textarea>', $form);
		$this->assertStringNotContainsString ('<c>', $form);
		$this->setRequest ('rack', 'edit', $args);
		updateRack();
		$this->assertSame ($rack_values, $this->objectRow ($rack_id));
	}

	/**
	 * @group small
	 */
	public function testPortForms ()
	{
		$name = $this->text ('port');
		$label = $this->text ('port label');
		$this->setRequest
		(
			'object',
			'ports',
			array
			(
				'object_id' => $this->object_id,
				'port_name' => " {$name} ",
				'port_type_id' => '1-24',
				'port_label' => $label,
				'port_l2address' => '',
			)
		);
		addPortForObject();
		$this->assertSame (array ($label), $this->column ('SELECT label FROM Port WHERE object_id = ? AND name = ?', array ($this->object_id, $name)));
		$this->assertMessagesEscaped();

		// the label is optional
		$this->setRequest
		(
			'object',
			'ports',
			array ('object_id' => $this->object_id, 'port_name' => 'nolabel', 'port_type_id' => '1-24', 'port_l2address' => '')
		);
		addPortForObject();
		$this->assertCount (1, getPortIDs ($this->object_id, 'nolabel'));

		// one port per line, the first word is the name
		$word = $this->word ('multi');
		$args = array ('object_id' => $this->object_id, 'format' => 'ssv1', 'port_type' => '1-24', 'input' => "{$word}\n");
		$this->setRequest ('object', 'ports', $args);
		addMultiPorts();
		$this->assertCount (1, getPortIDs ($this->object_id, $word));
		// the second time the port is found by the same name and updated
		$this->setRequest ('object', 'ports', $args);
		addMultiPorts();
		$this->assertCount (1, getPortIDs ($this->object_id, $word));

		$this->setRequest
		(
			'object',
			'ports',
			array
			(
				'object_id' => $this->object_id,
				'port_name' => 'b&<%u>',
				'port_label' => "l\"'%u",
				'port_type_id' => '1-24',
				'port_numbering_start' => '1',
				'port_numbering_count' => '2',
			)
		);
		addBulkPorts();
		$this->assertSame
		(
			array ("l\"'1", "l\"'2"),
			$this->column ("SELECT label FROM Port WHERE object_id = ? AND name IN ('b&<1>', 'b&<2>') ORDER BY name", array ($this->object_id))
		);
	}

	/**
	 * @group small
	 */
	public function testIPAllocationAndNAT ()
	{
		$net_id = createIPv4Prefix ('10.73.73.0/24', $this->myString ('net'), TRUE);
		try
		{
			$this->checkIPAllocationAndNAT();
		}
		finally
		{
			usePreparedDeleteBlade ('IPv4NAT', array ('object_id' => $this->object_id));
			usePreparedDeleteBlade ('IPv4Allocation', array ('object_id' => $this->object_id));
			usePreparedExecuteBlade ('DELETE FROM IPv4Address WHERE ip BETWEEN ? AND ?', array (ip4_bin2db (ip4_parse ('10.73.73.0')), ip4_bin2db (ip4_parse ('10.73.73.255'))));
			usePreparedDeleteBlade ('IPv4Network', array ('id' => $net_id));
		}
	}

	protected function checkIPAllocationAndNAT ()
	{
		$ip_bin = ip4_parse ('10.73.73.1');
		bindIPToObject ($ip_bin, $this->object_id, 'eth0', 'regular');
		$name = $this->text ('osif');
		$this->setRequest
		(
			'object',
			'ip',
			array ('object_id' => $this->object_id, 'ip' => '10.73.73.1', 'bond_name' => $name, 'bond_type' => 'regular')
		);
		updIPAllocation();
		$this->assertSame (array ($name), $this->column ('SELECT name FROM IPv4Allocation WHERE object_id = ? AND ip = ?', array ($this->object_id, ip4_bin2db ($ip_bin))));

		$description = $this->text ('nat');
		$args = array
		(
			'object_id' => $this->object_id,
			'proto' => 'TCP',
			'localip' => '10.73.73.1',
			'localport' => '8080',
			'remoteip' => '10.73.73.2',
			'remoteport' => '80',
			'description' => $description,
		);
		$this->setRequest ('object', 'nat4', $args);
		addPortForwarding();
		$this->assertSame (array ($description), $this->column ('SELECT description FROM IPv4NAT WHERE object_id = ?', array ($this->object_id)));
		$form = getOutputOf ('renderNATv4ForObject', $this->object_id);
		$this->assertStringContainsString ("name='description' value='" . htmlspecialchars ($description, ENT_QUOTES, 'UTF-8') . "'>", $form);
		$this->assertStringNotContainsString ('<c>', $form);
		$args['description'] = $description . ' 2';
		$this->setRequest ('object', 'nat4', $args);
		updPortForwarding();
		$this->assertSame (array ($description . ' 2'), $this->column ('SELECT description FROM IPv4NAT WHERE object_id = ?', array ($this->object_id)));
	}

	/**
	 * @group small
	 */
	public function testConfigValues ()
	{
		global $configCache, $remote_username;
		$saved = getConfigVar ('enterprise');
		$saved_cache = $configCache;
		try
		{
			$this->setRequest ('ui', 'edit', array ('num_vars' => '1', '0_varname' => 'enterprise', '0_varvalue' => self::TEXT));
			updateUI();
			$this->assertSame (array (self::TEXT), $this->column ("SELECT varvalue FROM Config WHERE varname = 'enterprise'", array()));
			// the same value again is not a change
			$configCache = loadConfigDefaults();
			$this->assertFalse (isConfigVarChanged ('enterprise', self::TEXT));

			$remote_username = $this->myString ('user');
			$this->setRequest ('myaccount', 'interface', array ('num_vars' => '1', '0_varname' => 'VENDOR_SIEVE', '0_varvalue' => self::TEXT));
			saveMyPreferences();
			$this->assertSame
			(
				array (self::TEXT),
				$this->column ("SELECT varvalue FROM UserConfig WHERE varname = 'VENDOR_SIEVE' AND user = ?", array ($remote_username))
			);
		}
		finally
		{
			usePreparedDeleteBlade ('UserConfig', array ('user' => $this->myString ('user')));
			usePreparedUpdateBlade ('Config', array ('varvalue' => $saved), array ('varname' => 'enterprise'));
			$configCache = $saved_cache;
		}
	}

	/**
	 * @group small
	 */
	public function testDefaultFilterOperator ()
	{
		global $configCache;
		$saved = $configCache['FILTER_DEFAULT_ANDOR']['varvalue'];
		try
		{
			foreach (array ('or' => 'or', 'and' => 'and', "x' onclick" => 'and', '' => 'and') as $stored => $expected)
			{
				$configCache['FILTER_DEFAULT_ANDOR']['varvalue'] = $stored;
				$this->assertSame ($expected, getDefaultFilterAndor());
			}
		}
		finally
		{
			$configCache['FILTER_DEFAULT_ANDOR']['varvalue'] = $saved;
		}
	}

	/**
	 * @group small
	 */
	public function testSLB ()
	{
		$vs_name = $this->text ('vs');
		$vs_ids = $pool_ids = array();
		try
		{
			$this->setRequest
			(
				'ipv4slb',
				'default',
				array ('name' => $vs_name, 'proto' => 'TCP', 'vip' => '10.73.74.1', 'vport' => '80', 'vsconfig' => '', 'rsconfig' => '')
			);
			addVService();
			$vs_ids = $this->column ('SELECT id FROM IPv4VS WHERE name = ?', array ($vs_name));
			$this->assertCount (1, $vs_ids);
			$this->assertMessagesEscaped();

			$vs_name .= ' 2';
			$this->setRequest
			(
				'ipv4vs',
				'edit',
				array
				(
					'vs_id' => $vs_ids[0],
					'taglist' => array(),
					'name' => $vs_name,
					'proto' => 'TCP',
					'vip' => '10.73.74.1',
					'vport' => '80',
					'vsconfig' => '',
					'rsconfig' => '',
				)
			);
			updateVService();
			$this->assertSame (array ($vs_name), $this->column ('SELECT name FROM IPv4VS WHERE id = ?', array ($vs_ids[0])));
			$form = getOutputOf ('renderEditVService', $vs_ids[0]);
			$this->assertStringContainsString ("name=name value='" . htmlspecialchars ($vs_name, ENT_QUOTES, 'UTF-8') . "'>", $form);
			$this->assertStringNotContainsString ('<c>', $form);

			$pool_name = $this->text ('pool');
			$this->setRequest ('ipv4slb', 'rspools', array ('name' => $pool_name, 'vsconfig' => '', 'rsconfig' => ''));
			addRSPool();
			$pool_ids = $this->column ('SELECT id FROM IPv4RSPool WHERE name = ?', array ($pool_name));
			$this->assertCount (1, $pool_ids);
			$this->assertMessagesEscaped();
			$this->setRequest ('ipv4rspool', 'edit', array ('pool_id' => $pool_ids[0]));
			$form = getOutputOf ('renderEditRSPool', $pool_ids[0]);
			$this->assertStringContainsString ("name=name value='" . htmlspecialchars ($pool_name, ENT_QUOTES, 'UTF-8') . "'>", $form);

			$this->setRequest
			(
				'ipv4rspool',
				'editlblist',
				array
				(
					'pool_id' => $pool_ids[0],
					'object_id' => (string) $this->object_id,
					'vs_id' => (string) $vs_ids[0],
					'vsconfig' => '',
					'rsconfig' => '',
					'prio' => self::TEXT,
				)
			);
			addLoadBalancer();
			$this->assertSame (array (self::TEXT), $this->column ('SELECT prio FROM IPv4LB WHERE object_id = ?', array ($this->object_id)));
		}
		finally
		{
			usePreparedDeleteBlade ('IPv4LB', array ('object_id' => $this->object_id));
			foreach ($pool_ids as $id)
				usePreparedDeleteBlade ('IPv4RSPool', array ('id' => $id));
			foreach ($vs_ids as $id)
				usePreparedDeleteBlade ('IPv4VS', array ('id' => $id));
		}
	}

	/**
	 * @group small
	 */
	public function testImportPTRData ()
	{
		$net_id = createIPv4Prefix ('10.73.75.0/24', $this->myString ('net'), TRUE);
		try
		{
			$name = $this->text ('ptr');
			$this->setRequest
			(
				'ipv4net',
				'liveptr',
				array ('id' => $net_id, 'addrcount' => '1', 'import_1' => 'on', 'addr_1' => '10.73.75.10', 'descr_1' => $name)
			);
			importPTRData();
			$this->assertSame (array ($name), $this->column ('SELECT name FROM IPv4Address WHERE ip = ?', array (ip4_bin2db (ip4_parse ('10.73.75.10')))));
		}
		finally
		{
			usePreparedExecuteBlade ('DELETE FROM IPv4Address WHERE ip BETWEEN ? AND ?', array (ip4_bin2db (ip4_parse ('10.73.75.0')), ip4_bin2db (ip4_parse ('10.73.75.255'))));
			usePreparedDeleteBlade ('IPv4Network', array ('id' => $net_id));
		}
	}

	/**
	 * @group small
	 */
	public function testRackCode ()
	{
		$saved_code = loadScript ('RackCode');
		$saved_cache = loadScript ('RackCodeCache');
		try
		{
			$code = "# " . self::TEXT . " </textarea>\r\nallow {\$userid_1}\r\n";
			$this->setRequest ('perms', 'edit', array ('rackcode' => $code));
			saveRackCode();
			$stored = loadScript ('RackCode');
			$this->assertSame (str_replace ("\r", '', $code), $stored);
			$parsed = getRackCode ($stored);
			$this->assertSame ('ACK', $parsed['result']);
			$this->assertSame ($parsed, unserialize (base64_decode (loadScript ('RackCodeCache'))));

			foreach (array ('renderRackCodeViewer', 'renderRackCodeEditor') as $func_name)
			{
				$output = getOutputOf ($func_name);
				$this->assertStringContainsString (htmlspecialchars ($stored, ENT_QUOTES, 'UTF-8') . '</textarea>', $output, $func_name);
				$this->assertStringNotContainsString ('<c>', $output, $func_name);
			}

			// The parser quotes what it does not understand, the message is HTML.
			$this->setRequest ('perms', 'edit', array ('rackcode' => 'allow x<c>y'));
			saveRackCode();
			$this->assertSame ($stored, loadScript ('RackCode'));
			global $log_messages;
			$this->assertNotEmpty ($log_messages);
			$this->assertMessagesEscaped();
			$this->assertStringContainsString ('&lt;c&gt;', var_export ($log_messages, TRUE));

			$this->setRequest ('perms', 'edit', array ('code' => 'allow x<c>y'));
			$output = getOutputOf ('verifyCodeAJAX');
			$this->assertStringStartsWith ("NAK\n", $output);
			$this->assertStringNotContainsString ('<c>', $output);
			$this->assertStringContainsString ('&lt;c&gt;', $output);
			$this->setRequest ('perms', 'edit', array ('code' => "# <c> & \"\nallow {\$userid_1}"));
			$this->assertSame ("ACK\n", getOutputOf ('verifyCodeAJAX'));
		}
		finally
		{
			saveScript ('RackCode', $saved_code);
			saveScript ('RackCodeCache', $saved_cache);
		}
	}

	// The configured community is the initial value of three inputs.
	/**
	 * @group small
	 */
	public function testSNMPFormAndRealm ()
	{
		global $configCache;
		$saved = $configCache['DEFAULT_SNMP_COMMUNITY']['varvalue'];
		try
		{
			$configCache['DEFAULT_SNMP_COMMUNITY']['varvalue'] = self::TEXT;
			$this->setRequest ('object', 'snmpportfinder', array ('object_id' => $this->object_id));
			$form = getOutputOf ('renderSNMPPortFinder', $this->object_id);
			$this->assertStringContainsString ("id=communityv1 value='" . htmlspecialchars (self::TEXT, ENT_QUOTES, 'UTF-8') . "'>", $form);
			$this->assertStringContainsString ("name=sec_name value='" . htmlspecialchars (self::TEXT, ENT_QUOTES, 'UTF-8') . "'>", $form);
			$this->assertStringNotContainsString ('<c>', $form);
		}
		finally
		{
			$configCache['DEFAULT_SNMP_COMMUNITY']['varvalue'] = $saved;
		}
	}
}
