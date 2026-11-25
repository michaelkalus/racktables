<?php

// The real server and VS group operations of an RS pool page must only
// change real servers and triplets of that RS pool. The triplet operations
// are also available on the load balancer and VS group pages, where they
// must keep working.
class SLBPoolOwnershipTest extends RTTestCase
{
	protected static $poola_id, $poolb_id;
	protected static $vs_id;
	protected static $lba_id, $lbb_id;
	protected static $vip_bin;
	protected $rsa_id, $rsb_id;
	protected $request_args = array();

	public static function setUpBeforeClass () : void
	{
		// add sample data
		self::$poola_id = commitCreateRSPool (self::myStringStatic ('pool a', __CLASS__));
		self::$poolb_id = commitCreateRSPool (self::myStringStatic ('pool b', __CLASS__));
		usePreparedInsertBlade ('VS', array ('name' => self::myStringStatic ('vs', __CLASS__)));
		self::$vs_id = lastInsertID();
		self::$vip_bin = ip_parse ('192.0.2.10');
		usePreparedInsertBlade ('VSIPs', array ('vs_id' => self::$vs_id, 'vip' => self::$vip_bin));
		usePreparedInsertBlade ('VSPorts', array ('vs_id' => self::$vs_id, 'proto' => 'TCP', 'vport' => 80));
		self::$lba_id = commitAddObject (self::myStringStatic ('lb a', __CLASS__), NULL, 4, NULL);
		self::$lbb_id = commitAddObject (self::myStringStatic ('lb b', __CLASS__), NULL, 4, NULL);
	}

	public static function tearDownAfterClass () : void
	{
		// remove sample data
		commitDeleteObject (self::$lba_id);
		commitDeleteObject (self::$lbb_id);
		commitDeleteVSG (self::$vs_id);
		commitDeleteRSPool (self::$poola_id);
		commitDeleteRSPool (self::$poolb_id);
	}

	public function setUp () : void
	{
		addRStoRSPool (self::$poola_id, ip_parse ('192.0.2.101'), 80, 'yes', '', 'rs a');
		$this->rsa_id = lastInsertID();
		addRStoRSPool (self::$poolb_id, ip_parse ('192.0.2.102'), 80, 'yes', '', 'rs b');
		$this->rsb_id = lastInsertID();
		// one triplet of each pool, each on its own load balancer
		foreach (array (self::$poola_id => self::$lba_id, self::$poolb_id => self::$lbb_id) as $pool_id => $lb_id)
		{
			$key = array ('object_id' => $lb_id, 'vs_id' => self::$vs_id, 'rspool_id' => $pool_id);
			addSLBPortLink ($key + array ('proto' => 'TCP', 'vport' => 80));
			addSLBIPLink ($key + array ('vip' => self::$vip_bin));
		}
	}

	public function tearDown () : void
	{
		$this->clearRequest();
		usePreparedDeleteBlade ('IPv4RS', array ('rspool_id' => self::$poola_id));
		usePreparedDeleteBlade ('IPv4RS', array ('rspool_id' => self::$poolb_id));
		usePreparedDeleteBlade ('VSEnabledPorts', array ('vs_id' => self::$vs_id));
		usePreparedDeleteBlade ('VSEnabledIPs', array ('vs_id' => self::$vs_id));
	}

	protected function clearRequest ()
	{
		global $sic;
		foreach ($this->request_args as $argname)
			unset ($sic[$argname], $_REQUEST[$argname]);
		$this->request_args = array();
	}

	// run the ophandler the way index.php?module=redirect does
	protected function runOp ($pageno_, $tabno_, $op_, $args)
	{
		global $ophandler, $pageno, $tabno, $op, $sic;
		$this->clearRequest();
		$pageno = $pageno_;
		$tabno = $tabno_;
		$op = $op_;
		foreach ($args as $argname => $value)
		{
			$sic[$argname] = $_REQUEST[$argname] = $value;
			$this->request_args[] = $argname;
		}
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function assertOpRefused ($pageno_, $tabno_, $op_, $args)
	{
		try
		{
			$this->runOp ($pageno_, $tabno_, $op_, $args);
			$refused = FALSE;
		}
		catch (InvalidRequestArgException $e)
		{
			$refused = TRUE;
		}
		$this->assertTrue ($refused, 'the request must be refused');
	}

	// values of the hidden inputs of the addLink form in $html
	protected function getAddLinkHiddenFields ($html)
	{
		$this->assertSame (1, preg_match ('/<form method=post id=addLink .*?<\/form>/s', $html, $m), 'the addLink form must be there');
		preg_match_all ('/<input type=hidden name="?([a-z_]+)"?(?: id=[a-z_]+)? value="?([^"> ]*)"?>/', $m[0], $inputs, PREG_SET_ORDER);
		$ret = array();
		foreach ($inputs as $input)
			$ret[$input[1]] = html_entity_decode ($input[2], ENT_QUOTES);
		return $ret;
	}

	// Go through the "add VS group" forms of a page the way js/slb_editor.js
	// does: render the first form and send its fields with the selected values
	// to the get-slb-form AJAX handler. Return the hidden fields of the first
	// form and of the form that handler returns.
	protected function renderAddLinkForms ($pageno_, $bypass_name, $bypass_value, $selected)
	{
		global $pageno, $tabno, $sic, $configCache;
		$this->clearRequest();
		$pageno = $pageno_;
		$tabno = 'editvslinks';
		$sic[$bypass_name] = $_REQUEST[$bypass_name] = $bypass_value;
		$this->request_args[] = $bypass_name;
		$saved_listsrc = $configCache['IPV4LB_LISTSRC']['varvalue'];
		$configCache['IPV4LB_LISTSRC']['varvalue'] = '{$typeid_4}';
		try
		{
			$first = $this->getAddLinkHiddenFields (getOutputOf ('renderTripletForm', $bypass_value));
		}
		finally
		{
			$configCache['IPV4LB_LISTSRC']['varvalue'] = $saved_listsrc;
		}

		$ajax_args = array
		(
			'form' => http_build_query (array_merge ($first, $selected)),
			'action' => "?module=redirect&page={$pageno_}&tab=editvslinks&op=addLink",
		);
		foreach ($ajax_args as $argname => $value)
		{
			$sic[$argname] = $_REQUEST[$argname] = $value;
			$this->request_args[] = $argname;
		}
		$second = $this->getAddLinkHiddenFields (getOutputOf ('renderSLBFormAJAX'));
		return array ($first, $second);
	}

	protected function getRS ($rs_id)
	{
		$result = usePreparedSelectBlade ('SELECT rspool_id, rsport, comment FROM IPv4RS WHERE id = ?', array ($rs_id));
		return $result->fetch (PDO::FETCH_ASSOC);
	}

	protected function getTripletRows ($table, $lb_id, $pool_id)
	{
		$result = usePreparedSelectBlade
		(
			"SELECT vsconfig, rsconfig FROM {$table} WHERE object_id = ? AND vs_id = ? AND rspool_id = ?",
			array ($lb_id, self::$vs_id, $pool_id)
		);
		return $result->fetchAll (PDO::FETCH_ASSOC);
	}

	protected function tripletArgs ($lb_id, $pool_id)
	{
		return array ('object_id' => $lb_id, 'vs_id' => self::$vs_id, 'rspool_id' => $pool_id);
	}

	protected function updPortArgs ($lb_id, $pool_id, $enabled, $vsconfig)
	{
		$ret = $this->tripletArgs ($lb_id, $pool_id) + array
		(
			'proto' => 'TCP',
			'port' => 80,
			'vsconfig' => $vsconfig,
			'rsconfig' => '',
		);
		if ($enabled)
			$ret['enabled'] = 'on';
		return $ret;
	}

	protected function updIpArgs ($lb_id, $pool_id, $enabled, $vsconfig)
	{
		$ret = $this->tripletArgs ($lb_id, $pool_id) + array
		(
			'vip' => '192.0.2.10',
			'prio' => '',
			'vsconfig' => $vsconfig,
			'rsconfig' => '',
		);
		if ($enabled)
			$ret['enabled'] = 'on';
		return $ret;
	}

	protected function updRSArgs ($pool_id, $rs_id, $comment)
	{
		return array
		(
			'pool_id' => $pool_id,
			'rs_id' => $rs_id,
			'rsip' => '192.0.2.200',
			'rsport' => '8080',
			'inservice' => 'on',
			'rsconfig' => '',
			'comment' => $comment,
		);
	}

	/**
	 * @group small
	 */
	public function testDeleteOwnRealServer ()
	{
		$this->runOp ('ipv4rspool', 'editrslist', 'delRS', array ('pool_id' => self::$poola_id, 'id' => $this->rsa_id));
		$this->assertFalse ($this->getRS ($this->rsa_id));
	}

	/**
	 * @group small
	 */
	public function testDeleteOtherPoolRealServerRefused ()
	{
		$this->assertOpRefused ('ipv4rspool', 'editrslist', 'delRS', array ('pool_id' => self::$poola_id, 'id' => $this->rsb_id));
		$this->assertNotFalse ($this->getRS ($this->rsb_id), 'the real server must not be deleted');
	}

	/**
	 * @group small
	 */
	public function testUpdateOwnRealServer ()
	{
		$this->runOp ('ipv4rspool', 'editrslist', 'updRS', $this->updRSArgs (self::$poola_id, $this->rsa_id, 'changed'));
		$rs = $this->getRS ($this->rsa_id);
		$this->assertSame ('changed', $rs['comment']);
		$this->assertEquals (8080, $rs['rsport']);
	}

	/**
	 * @group small
	 */
	public function testUpdateOtherPoolRealServerRefused ()
	{
		$this->assertOpRefused ('ipv4rspool', 'editrslist', 'updRS', $this->updRSArgs (self::$poola_id, $this->rsb_id, 'changed'));
		$rs = $this->getRS ($this->rsb_id);
		$this->assertSame ('rs b', $rs['comment'], 'the real server must not be changed');
		$this->assertEquals (80, $rs['rsport']);
	}

	/**
	 * @group small
	 */
	public function testRemoveOwnTripletOnPoolPage ()
	{
		$this->runOp ('ipv4rspool', 'editvslinks', 'del', array ('pool_id' => self::$poola_id) + $this->tripletArgs (self::$lba_id, self::$poola_id));
		$this->assertCount (0, $this->getTripletRows ('VSEnabledPorts', self::$lba_id, self::$poola_id));
		$this->assertCount (0, $this->getTripletRows ('VSEnabledIPs', self::$lba_id, self::$poola_id));
	}

	/**
	 * @group small
	 */
	public function testRemoveOtherPoolTripletOnPoolPageRefused ()
	{
		$this->assertOpRefused ('ipv4rspool', 'editvslinks', 'del', array ('pool_id' => self::$poola_id) + $this->tripletArgs (self::$lbb_id, self::$poolb_id));
		$this->assertCount (1, $this->getTripletRows ('VSEnabledPorts', self::$lbb_id, self::$poolb_id));
		$this->assertCount (1, $this->getTripletRows ('VSEnabledIPs', self::$lbb_id, self::$poolb_id));
	}

	/**
	 * @group small
	 */
	public function testUpdateOwnTripletOnPoolPage ()
	{
		$this->runOp ('ipv4rspool', 'editvslinks', 'updPort', array ('pool_id' => self::$poola_id) + $this->updPortArgs (self::$lba_id, self::$poola_id, TRUE, 'changed'));
		$this->assertSame (array (array ('vsconfig' => 'changed', 'rsconfig' => NULL)), $this->getTripletRows ('VSEnabledPorts', self::$lba_id, self::$poola_id));
	}

	/**
	 * @group small
	 */
	public function testUpdateOtherPoolTripletOnPoolPageRefused ()
	{
		$this->assertOpRefused ('ipv4rspool', 'editvslinks', 'updPort', array ('pool_id' => self::$poola_id) + $this->updPortArgs (self::$lbb_id, self::$poolb_id, TRUE, 'changed'));
		$this->assertSame (array (array ('vsconfig' => NULL, 'rsconfig' => NULL)), $this->getTripletRows ('VSEnabledPorts', self::$lbb_id, self::$poolb_id));
	}

	/**
	 * @group small
	 */
	public function testDisableOtherPoolTripletIPOnPoolPageRefused ()
	{
		$this->assertOpRefused ('ipv4rspool', 'editvslinks', 'updIp', array ('pool_id' => self::$poola_id) + $this->updIpArgs (self::$lbb_id, self::$poolb_id, FALSE, ''));
		$this->assertCount (1, $this->getTripletRows ('VSEnabledIPs', self::$lbb_id, self::$poolb_id));
	}

	/**
	 * @group small
	 */
	public function testEnableOtherPoolTripletIPOnPoolPageRefused ()
	{
		$this->assertOpRefused ('ipv4rspool', 'editvslinks', 'updIp', array ('pool_id' => self::$poola_id) + $this->updIpArgs (self::$lba_id, self::$poolb_id, TRUE, ''));
		$this->assertCount (0, $this->getTripletRows ('VSEnabledIPs', self::$lba_id, self::$poolb_id));
	}

	/**
	 * @group small
	 */
	public function testCreateOtherPoolTripletOnPoolPageRefused ()
	{
		$this->assertOpRefused
		(
			'ipv4rspool',
			'editvslinks',
			'addLink',
			array ('pool_id' => self::$poola_id, 'enabled_vips' => array(), 'enabled_ports' => array ('TCP-80')) +
				$this->tripletArgs (self::$lba_id, self::$poolb_id)
		);
		$this->assertCount (0, $this->getTripletRows ('VSEnabledPorts', self::$lba_id, self::$poolb_id));
	}

	// The forms of the RS pool page carry the RS pool as rspool_id, as the
	// forms of the load balancer and VS group pages carry their own key field.
	// (renderNewTripletForm() can run only once per process.)

	/**
	 * @group small
	 */
	public function testAddLinkFormsOnPoolPage ()
	{
		$this->runOp ('ipv4rspool', 'editvslinks', 'del', array ('pool_id' => self::$poola_id) + $this->tripletArgs (self::$lba_id, self::$poola_id));
		list ($first, $second) = $this->renderAddLinkForms
		(
			'ipv4rspool',
			'pool_id',
			self::$poola_id,
			array ('object_id' => self::$lba_id, 'vs_id' => self::$vs_id)
		);
		foreach (array ($first, $second) as $fields)
		{
			$this->assertArrayHasKey ('rspool_id', $fields);
			$this->assertEquals (self::$poola_id, $fields['rspool_id']);
			$this->assertEquals (self::$poola_id, $fields['pool_id']);
		}
		$this->assertEquals (self::$lba_id, $second['object_id']);
		$this->assertEquals (self::$vs_id, $second['vs_id']);

		// submit the second form
		$this->runOp ('ipv4rspool', 'editvslinks', 'addLink', $second + array ('enabled_vips' => array ('192.0.2.10'), 'enabled_ports' => array ('TCP-80')));
		$this->assertCount (1, $this->getTripletRows ('VSEnabledPorts', self::$lba_id, self::$poola_id));
		$this->assertCount (1, $this->getTripletRows ('VSEnabledIPs', self::$lba_id, self::$poola_id));
	}

	/**
	 * @group small
	 */
	public function testTripletOpsOnObjectPage ()
	{
		$this->runOp ('object', 'editvslinks', 'updIp', $this->updIpArgs (self::$lbb_id, self::$poolb_id, TRUE, 'changed'));
		$this->assertSame (array (array ('vsconfig' => 'changed', 'rsconfig' => NULL)), $this->getTripletRows ('VSEnabledIPs', self::$lbb_id, self::$poolb_id));
		$this->runOp ('object', 'editvslinks', 'del', $this->tripletArgs (self::$lbb_id, self::$poolb_id));
		$this->assertCount (0, $this->getTripletRows ('VSEnabledPorts', self::$lbb_id, self::$poolb_id));
		$this->assertCount (0, $this->getTripletRows ('VSEnabledIPs', self::$lbb_id, self::$poolb_id));
		$this->runOp
		(
			'object',
			'editvslinks',
			'addLink',
			array ('enabled_vips' => array ('192.0.2.10'), 'enabled_ports' => array ('TCP-80')) + $this->tripletArgs (self::$lbb_id, self::$poolb_id)
		);
		$this->assertCount (1, $this->getTripletRows ('VSEnabledPorts', self::$lbb_id, self::$poolb_id));
		$this->assertCount (1, $this->getTripletRows ('VSEnabledIPs', self::$lbb_id, self::$poolb_id));
	}

	/**
	 * @group small
	 */
	public function testTripletOpsOnVSPage ()
	{
		$this->runOp ('ipvs', 'editvslinks', 'updPort', $this->updPortArgs (self::$lbb_id, self::$poolb_id, TRUE, 'changed'));
		$this->assertSame (array (array ('vsconfig' => 'changed', 'rsconfig' => NULL)), $this->getTripletRows ('VSEnabledPorts', self::$lbb_id, self::$poolb_id));
		$this->runOp ('ipvs', 'editvslinks', 'updPort', $this->updPortArgs (self::$lbb_id, self::$poolb_id, FALSE, ''));
		$this->assertCount (0, $this->getTripletRows ('VSEnabledPorts', self::$lbb_id, self::$poolb_id));
		$this->runOp ('ipvs', 'editvslinks', 'del', $this->tripletArgs (self::$lbb_id, self::$poolb_id));
		$this->assertCount (0, $this->getTripletRows ('VSEnabledIPs', self::$lbb_id, self::$poolb_id));
		$this->runOp
		(
			'ipvs',
			'editvslinks',
			'addLink',
			array ('enabled_vips' => array ('192.0.2.10'), 'enabled_ports' => array ('TCP-80')) + $this->tripletArgs (self::$lbb_id, self::$poolb_id)
		);
		$this->assertCount (1, $this->getTripletRows ('VSEnabledPorts', self::$lbb_id, self::$poolb_id));
		$this->assertCount (1, $this->getTripletRows ('VSEnabledIPs', self::$lbb_id, self::$poolb_id));
	}
}
