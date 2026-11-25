<?php

// A function that starts a transaction must not leave it open, and must not
// leave its changes behind, when a statement in the middle fails.
class TransactionRollbackTest extends RTTestCase
{
	const TRIGGER = '`TransactionRollbackTest-fail`';
	protected $objects = array();
	protected $vs_id = NULL, $pool_id = NULL, $vip_bin, $heap_id = NULL;
	protected $vdom_id = NULL, $vst_id = NULL;
	protected $saved_request, $saved_sic, $saved_pageno, $saved_op, $saved_hook;

	public function setUp () : void
	{
		global $sic, $pageno, $op, $hook;
		$this->saved_request = $_REQUEST;
		$this->saved_sic = $sic;
		$this->saved_pageno = $pageno;
		$this->saved_op = $op;
		$this->saved_hook = $hook;
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $op, $hook, $dbxlink;
		$_REQUEST = $this->saved_request;
		$sic = $this->saved_sic;
		$pageno = $this->saved_pageno;
		$op = $this->saved_op;
		$hook = $this->saved_hook;
		if (isTransactionActive())
			$dbxlink->rollBack();
		$dbxlink->exec ('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
		if ($this->vs_id !== NULL)
		{
			usePreparedDeleteBlade ('VSEnabledPorts', array ('vs_id' => $this->vs_id));
			usePreparedDeleteBlade ('VSEnabledIPs', array ('vs_id' => $this->vs_id));
			commitDeleteVSG ($this->vs_id);
			commitDeleteRSPool ($this->pool_id);
		}
		if ($this->heap_id !== NULL)
			usePreparedDeleteBlade ('PatchCableHeap', array ('id' => $this->heap_id));
		foreach (array_reverse ($this->objects) as $object_id)
		{
			usePreparedDeleteBlade ('VLANSwitch', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $object_id));
			commitDeleteObject ($object_id);
		}
		if ($this->vst_id !== NULL)
		{
			usePreparedDeleteBlade ('VLANSTRule', array ('vst_id' => $this->vst_id));
			usePreparedDeleteBlade ('VLANSwitchTemplate', array ('id' => $this->vst_id));
			usePreparedDeleteBlade ('VLANDescription', array ('domain_id' => $this->vdom_id));
			usePreparedDeleteBlade ('VLANDomain', array ('id' => $this->vdom_id));
		}
		setMessageBuffering (FALSE);
		$GLOBALS['log_messages'] = array();
	}

	// Make the given statement fail for the rows that match the condition.
	protected function failOn ($event, $table, $condition)
	{
		global $dbxlink;
		try
		{
			$dbxlink->exec
			(
				'CREATE TRIGGER ' . self::TRIGGER . " {$event} ON `{$table}` FOR EACH ROW " .
				"IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF"
			);
		}
		catch (PDOException $e)
		{
			$this->markTestSkipped ('cannot create the trigger: ' . $e->getMessage());
		}
	}

	// The callback must fail with the given message and leave no transaction.
	protected function assertFailure ($message, $callback)
	{
		try
		{
			$callback();
			$this->fail ('the change must fail');
		}
		catch (Exception $e)
		{
			$this->assertStringContainsString ($message, $e->getMessage());
		}
		$this->assertFalse (isTransactionActive(), 'no transaction may be left open');
	}

	protected function fetchRows ($query, $args = array())
	{
		$result = usePreparedSelectBlade ($query, $args);
		return $result->fetchAll (PDO::FETCH_ASSOC);
	}

	protected function addObject ($name, $type_id = 4)
	{
		$object_id = commitAddObject ($this->myString ($name), NULL, $type_id, NULL);
		$this->objects[] = $object_id;
		return $object_id;
	}

	// run an ophandler the way index.php?module=redirect does
	protected function runOp ($pageno_, $op_, $args, $handler)
	{
		global $pageno, $op, $sic;
		$pageno = $pageno_;
		$op = $op_;
		$_REQUEST = $sic = $args;
		setMessageBuffering (TRUE);
		return $handler();
	}

	protected function addVS ()
	{
		$this->pool_id = commitCreateRSPool ($this->myString ('pool'));
		usePreparedInsertBlade ('VS', array ('name' => $this->myString ('vs')));
		$this->vs_id = lastInsertID();
		$this->vip_bin = ip_parse ('192.0.2.10');
		usePreparedInsertBlade ('VSIPs', array ('vs_id' => $this->vs_id, 'vip' => $this->vip_bin));
		usePreparedInsertBlade ('VSPorts', array ('vs_id' => $this->vs_id, 'proto' => 'TCP', 'vport' => 80));
		return $this->addObject ('balancer');
	}

	protected function tripletKey ($lb_id)
	{
		return array ('object_id' => $lb_id, 'vs_id' => $this->vs_id, 'rspool_id' => $this->pool_id);
	}

	protected function tripletRows ()
	{
		return array
		(
			'ports' => $this->fetchRows ('SELECT object_id, proto, vport, vsconfig FROM VSEnabledPorts WHERE vs_id = ?', array ($this->vs_id)),
			'ips' => $this->fetchRows ('SELECT object_id, vsconfig FROM VSEnabledIPs WHERE vs_id = ?', array ($this->vs_id)),
		);
	}

	/**
	 * @group small
	 */
	public function testAddSLBPortLink ()
	{
		$lb_id = $this->addVS();
		$this->failOn ('BEFORE INSERT', 'VSEnabledPorts', "NEW.object_id = {$lb_id}");
		$key = $this->tripletKey ($lb_id);
		$this->assertFailure ('injected failure', function () use ($key)
		{
			addSLBPortLink ($key + array ('proto' => 'TCP', 'vport' => 80));
		});
		$this->assertEquals (array ('ports' => array(), 'ips' => array()), $this->tripletRows());
	}

	/**
	 * @group small
	 */
	public function testAddSLBPortLinkInOuterTransaction ()
	{
		global $dbxlink;
		$lb_id = $this->addVS();
		$this->failOn ('BEFORE INSERT', 'VSEnabledPorts', "NEW.object_id = {$lb_id}");
		$key = $this->tripletKey ($lb_id);
		$dbxlink->beginTransaction();
		addSLBIPLink ($key + array ('vip' => $this->vip_bin));
		try
		{
			addSLBPortLink ($key + array ('proto' => 'TCP', 'vport' => 80));
			$this->fail ('the change must fail');
		}
		catch (PDOException $e)
		{
		}
		// the caller keeps its transaction and its own work
		$this->assertTrue (isTransactionActive());
		$dbxlink->commit();
		$rows = $this->tripletRows();
		$this->assertCount (0, $rows['ports']);
		$this->assertCount (1, $rows['ips']);
	}

	/**
	 * @group small
	 */
	public function testAddSLBIPLink ()
	{
		$lb_id = $this->addVS();
		$this->failOn ('BEFORE INSERT', 'VSEnabledIPs', "NEW.object_id = {$lb_id}");
		$key = $this->tripletKey ($lb_id);
		$vip_bin = $this->vip_bin;
		$this->assertFailure ('injected failure', function () use ($key, $vip_bin)
		{
			addSLBIPLink ($key + array ('vip' => $vip_bin));
		});
		$this->assertEquals (array ('ports' => array(), 'ips' => array()), $this->tripletRows());
	}

	/**
	 * @group small
	 */
	public function testCreateTriplet ()
	{
		$lb_id = $this->addVS();
		// the VIP is linked first, then the port fails
		$this->failOn ('BEFORE INSERT', 'VSEnabledPorts', "NEW.object_id = {$lb_id}");
		$args = $this->tripletKey ($lb_id) + array ('enabled_vips' => array ('192.0.2.10'), 'enabled_ports' => array ('TCP-80'));
		$self = $this;
		$this->assertFailure ('injected failure', function () use ($self, $args)
		{
			$self->runOp ('object', 'addLink', $args, 'createTriplet');
		});
		$this->assertEquals (array ('ports' => array(), 'ips' => array()), $this->tripletRows());
	}

	/**
	 * @group small
	 */
	public function testUpdateTripletConfig ()
	{
		$lb_id = $this->addVS();
		$this->failOn ('BEFORE INSERT', 'VSEnabledPorts', "NEW.object_id = {$lb_id}");
		$args = $this->tripletKey ($lb_id) + array
		(
			'proto' => 'TCP',
			'port' => 80,
			'enabled' => 'on',
			'vsconfig' => 'changed',
			'rsconfig' => '',
		);
		$self = $this;
		$this->assertFailure ('injected failure', function () use ($self, $args)
		{
			$self->runOp ('object', 'updPort', $args, 'updateTripletConfig');
		});
		$this->assertEquals (array ('ports' => array(), 'ips' => array()), $this->tripletRows());
	}

	/**
	 * @group small
	 */
	public function testRemoveTriplet ()
	{
		$lb_id = $this->addVS();
		$key = $this->tripletKey ($lb_id);
		addSLBIPLink ($key + array ('vip' => $this->vip_bin));
		addSLBPortLink ($key + array ('proto' => 'TCP', 'vport' => 80));
		$before = $this->tripletRows();
		// the VIP is unlinked first, then the port fails
		$this->failOn ('BEFORE DELETE', 'VSEnabledPorts', "OLD.object_id = {$lb_id}");
		$self = $this;
		$this->assertFailure ('injected failure', function () use ($self, $key)
		{
			$self->runOp ('object', 'del', $key, 'removeTriplet');
		});
		$this->assertEquals ($before, $this->tripletRows());
		$this->assertCount (1, $before['ips']);
	}

	/**
	 * @group small
	 */
	public function testDoVSMigrate ()
	{
		global $hook;
		$lb_id = $this->addVS();
		$key = $this->tripletKey ($lb_id);
		addSLBIPLink ($key + array ('vip' => $this->vip_bin));
		addSLBPortLink ($key + array ('proto' => 'TCP', 'vport' => 80));
		$before = $this->tripletRows();
		$hook['buildVSMigratePlan'] = function ($vs_id, $vs_id_list)
		{
			return array
			(
				'properties' => array ('vsconfig' => 'changed', 'rsconfig' => ''),
				'ports' => array(),
				'vips' => array(),
				'triplets' => array(),
			);
		};
		// the triplets are removed first, then the VS update fails
		$this->failOn ('BEFORE UPDATE', 'VS', "NEW.id = {$this->vs_id}");
		$self = $this;
		$args = array ('vs_id' => $this->vs_id, 'vs_list' => array ($this->vs_id), 'taglist' => array());
		$this->assertFailure ('injected failure', function () use ($self, $args)
		{
			$self->runOp ('ipvs', 'convert', $args, 'doVSMigrate');
		});
		$this->assertEquals ($before, $this->tripletRows());
		$this->assertCount (1, $before['ports']);
	}

	protected function portInfo ($port_id)
	{
		$rows = $this->fetchRows ('SELECT name, type, reservation_comment FROM Port WHERE id = ?', array ($port_id));
		return $rows[0];
	}

	/**
	 * @group small
	 */
	public function testImportDPData ()
	{
		$object_id = $this->addObject ('switch a', 8);
		$remote_id = $this->addObject ('switch b', 8);
		// 100Base-TX and 1000Base-T
		$port_a = commitAddPort ($object_id, 'a', '1-19', '', '');
		$port_b = commitAddPort ($remote_id, 'b', '1-24', '', '');
		// the outer interface of port a is changed first, then the link fails
		$this->failOn ('BEFORE INSERT', 'Link', "NEW.porta IN ({$port_a}, {$port_b})");
		$self = $this;
		$args = array
		(
			'object_id' => $object_id,
			'nports' => 1,
			'do_0' => 'on',
			'ports_0' => "a_id:{$port_a},b_id:{$port_b},a_oif:24",
		);
		$this->assertFailure ('injected failure', function () use ($self, $args)
		{
			$self->runOp ('object', 'importDPData', $args, 'importDPData');
		});
		$port = $this->portInfo ($port_a);
		$this->assertEquals (19, $port['type']);
		$this->assertCount (0, $this->fetchRows ('SELECT * FROM Link WHERE porta IN (?, ?) OR portb IN (?, ?)', array ($port_a, $port_b, $port_a, $port_b)));
	}

	protected function addRack ($height)
	{
		$row_id = $this->addObject ('row', 1561);
		$rack_id = $this->addObject ('rack', 1560);
		commitUpdateAttrValue ($rack_id, 27, $height);
		commitLinkEntities ('row', $row_id, 'rack', $rack_id);
		return $rack_id;
	}

	/**
	 * @group small
	 */
	public function testProcessGridForm ()
	{
		$rack_id = $this->addRack (2);
		// no atom is checked on the form, so all of them change from free
		// to absent, unit 2 goes first
		$this->failOn ('BEFORE INSERT', 'RackSpace', "NEW.rack_id = {$rack_id} AND NEW.unit_no = 1");
		$self = $this;
		$this->assertFailure ('injected failure', function () use ($self, $rack_id)
		{
			// processGridForm() as the rack design form uses it
			$self->runOp ('rack', 'updateRack', array ('rack_id' => $rack_id), 'updateRackDesign');
		});
		$this->assertCount (0, $this->fetchRows ('SELECT * FROM RackSpace WHERE rack_id = ?', array ($rack_id)));
	}

	/**
	 * @group small
	 */
	public function testUpdateObjectToIncompatibleType ()
	{
		$object_id = $this->addObject ('object');
		$args = array
		(
			'object_id' => $object_id,
			'object_name' => $this->myString ('new name'),
			'object_label' => '',
			'object_asset_no' => '',
			'object_comment' => '',
			'num_attrs' => 0,
			'taglist' => array(),
			// a rack is not among the type change options of an object
			'object_type_id' => 1560,
		);
		$self = $this;
		// the name is changed first, then the type change is refused
		$this->assertFailure ('incompatible', function () use ($self, $args)
		{
			$self->runOp ('object', 'update', $args, 'updateObject');
		});
		$this->assertEquals
		(
			array (array ('name' => $this->myString ('object'), 'objtype_id' => 4)),
			$this->fetchRows ('SELECT name, objtype_id FROM Object WHERE id = ?', array ($object_id))
		);
	}

	/**
	 * @group small
	 */
	public function testCommitUpdatePortComment ()
	{
		$object_id = $this->addObject ('object');
		$port_id = commitAddPort ($object_id, 'a', '1-24', '', '');
		// the comment is changed first, then the log entry fails
		$this->failOn ('BEFORE INSERT', 'PortLog', "NEW.port_id = {$port_id}");
		$this->assertFailure ('injected failure', function () use ($port_id)
		{
			commitUpdatePortComment ($port_id, 'reserved');
		});
		$port = $this->portInfo ($port_id);
		$this->assertNull ($port['reservation_comment']);
	}

	protected function addVST ()
	{
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
			array ('vst_id' => $this->vst_id, 'rule_no' => 100, 'port_pcre' => '/./', 'port_role' => 'downlink', 'wrt_vlans' => '', 'description' => 'old')
		);
	}

	protected function VSTState ()
	{
		return array
		(
			'VLANSwitchTemplate' => $this->fetchRows ('SELECT mutex_rev, saved_by FROM VLANSwitchTemplate WHERE id = ?', array ($this->vst_id)),
			'VLANSTRule' => $this->fetchRows ('SELECT rule_no, port_pcre, description FROM VLANSTRule WHERE vst_id = ? ORDER BY rule_no', array ($this->vst_id)),
		);
	}

	protected function newVSTRules ()
	{
		return array
		(
			array ('rule_no' => 100, 'port_pcre' => '/^a/', 'port_role' => 'access', 'wrt_vlans' => '', 'description' => 'new 1'),
			array ('rule_no' => 200, 'port_pcre' => '/^b/', 'port_role' => 'access', 'wrt_vlans' => '', 'description' => 'new 2'),
		);
	}

	/**
	 * @group small
	 */
	public function testCommitUpdateVSTRules ()
	{
		$this->addVST();
		$before = $this->VSTState();
		// the old rules are deleted and the first new one is added, then the second one fails
		$this->failOn ('BEFORE INSERT', 'VLANSTRule', "NEW.vst_id = {$this->vst_id} AND NEW.rule_no = 200");
		$vst_id = $this->vst_id;
		$rules = $this->newVSTRules();
		$this->assertFailure ('injected failure', function () use ($vst_id, $rules)
		{
			commitUpdateVSTRules ($vst_id, 0, $rules);
		});
		$this->assertEquals ($before, $this->VSTState());
	}

	/**
	 * @group small
	 */
	public function testCommitUpdateVSTRulesExpired ()
	{
		$this->addVST();
		$before = $this->VSTState();
		$vst_id = $this->vst_id;
		$rules = $this->newVSTRules();
		$this->assertFailure ('already saved', function () use ($vst_id, $rules)
		{
			commitUpdateVSTRules ($vst_id, 1, $rules);
		});
		$this->assertEquals ($before, $this->VSTState());
	}

	protected function addHeap ()
	{
		$compat = $this->fetchRows ('SELECT pctype_id, connector_id FROM PatchCableConnectorCompat ORDER BY pctype_id, connector_id LIMIT 1');
		usePreparedInsertBlade
		(
			'PatchCableHeap',
			array
			(
				'pctype_id' => $compat[0]['pctype_id'],
				'end1_conn_id' => $compat[0]['connector_id'],
				'end2_conn_id' => $compat[0]['connector_id'],
				'amount' => 10,
				'description' => $this->myString ('heap'),
			)
		);
		$this->heap_id = lastInsertID();
		$this->failOn ('BEFORE INSERT', 'PatchCableHeapLog', "NEW.heap_id = {$this->heap_id}");
	}

	protected function heapState ()
	{
		return array
		(
			'amount' => $this->fetchRows ('SELECT amount FROM PatchCableHeap WHERE id = ?', array ($this->heap_id)),
			'log' => $this->fetchRows ('SELECT * FROM PatchCableHeapLog WHERE heap_id = ?', array ($this->heap_id)),
		);
	}

	/**
	 * @group small
	 */
	public function testCommitModifyPatchCableAmount ()
	{
		$this->addHeap();
		$heap_id = $this->heap_id;
		// the amount is changed first, then the log entry fails
		$this->assertFailure ('injected failure', function () use ($heap_id)
		{
			commitModifyPatchCableAmount ($heap_id, -1);
		});
		$this->assertEquals (array ('amount' => array (array ('amount' => 10)), 'log' => array()), $this->heapState());
	}

	/**
	 * @group small
	 */
	public function testCommitSetPatchCableAmount ()
	{
		$this->addHeap();
		$heap_id = $this->heap_id;
		// the amount is changed first, then the log entry fails
		$this->assertFailure ('injected failure', function () use ($heap_id)
		{
			commitSetPatchCableAmount ($heap_id, 5);
		});
		$this->assertEquals (array ('amount' => array (array ('amount' => 10)), 'log' => array()), $this->heapState());
	}

	/**
	 * @group small
	 */
	public function testSaveDownlinksReverb ()
	{
		$this->addVST();
		$object_id = $this->addObject ('switch', 8);
		usePreparedInsertBlade
		(
			'VLANSwitch',
			array ('object_id' => $object_id, 'domain_id' => $this->vdom_id, 'template_id' => $this->vst_id)
		);
		commitAddPort ($object_id, 'gi0/1', '1-24', '', '');
		$conf = array ('mode' => 'trunk', 'allowed' => array (5), 'native' => 0);
		add8021QPort ($object_id, 'gi0/1', $conf);
		$before = getStored8021QConfig ($object_id, 'desired');
		// the port is changed first, then the switch update fails
		$this->failOn ('BEFORE UPDATE', 'VLANSwitch', "NEW.object_id = {$object_id}");
		$changes = array
		(
			'gi0/1' => array ('vst_role' => 'downlink', 'mode' => 'trunk', 'allowed' => array (5, 7), 'wrt_vlans' => array (array ('from' => 2, 'to' => 4094)), 'native' => 0),
		);
		$this->assertFailure ('injected failure', function () use ($object_id, $changes)
		{
			saveDownlinksReverb ($object_id, $changes);
		});
		$this->assertEquals ($before, getStored8021QConfig ($object_id, 'desired'));
		$this->assertEquals (array (5), $before['gi0/1']['allowed']);
	}
}
