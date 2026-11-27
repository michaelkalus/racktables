<?php

// A NAT rule of all protocols has no ports and is stored with 0 for both. The
// handlers that delete and update a rule use the same, whatever the request
// carries in the port fields (issue #154).
class NATRulePortsTest extends RTTestCase
{
	private $saved_request, $saved_sic, $saved_pageno, $saved_tabno;
	private $object_id, $net_id;

	public function setUp () : void
	{
		global $sic, $pageno, $tabno;
		$this->saved_request = $_REQUEST;
		$this->saved_sic = $sic;
		$this->saved_pageno = $pageno;
		$this->saved_tabno = $tabno;
		$this->object_id = commitAddObject ($this->myString ('server'), NULL, 4, NULL);
		$this->net_id = createIPv4Prefix ('10.154.154.0/24', $this->myString ('net'), TRUE);
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $tabno, $log_messages;
		setMessageBuffering (FALSE);
		$log_messages = array();
		$_REQUEST = $this->saved_request;
		$sic = $this->saved_sic;
		$pageno = $this->saved_pageno;
		$tabno = $this->saved_tabno;
		usePreparedDeleteBlade ('IPv4NAT', array ('object_id' => $this->object_id));
		usePreparedExecuteBlade ('DELETE FROM IPv4Address WHERE ip BETWEEN ? AND ?', array (ip4_bin2db (ip4_parse ('10.154.154.0')), ip4_bin2db (ip4_parse ('10.154.154.255'))));
		usePreparedDeleteBlade ('IPv4Network', array ('id' => $this->net_id));
		commitDeleteObject ($this->object_id);
	}

	// Set up the request the way transformRequestData() leaves it.
	private function setRequest ($args)
	{
		global $sic, $pageno, $tabno, $log_messages;
		$pageno = 'object';
		$tabno = 'nat4';
		$args += array ('object_id' => (string) $this->object_id, 'localip' => '10.154.154.1', 'remoteip' => '10.154.154.2');
		$sic = $args;
		$_REQUEST = array();
		foreach ($args as $key => $value)
			$_REQUEST[$key] = htmlspecialchars ($value, ENT_QUOTES, 'UTF-8');
		setMessageBuffering (TRUE);
		$log_messages = array();
	}

	private function rules ()
	{
		$result = usePreparedSelectBlade
		(
			'SELECT proto, localport, remoteport, description FROM IPv4NAT WHERE object_id = ? ORDER BY localport',
			array ($this->object_id)
		);
		$ret = array();
		foreach ($result->fetchAll (PDO::FETCH_NUM) as $row)
			$ret[] = implode (' ', $row);
		return $ret;
	}

	public function providerAllProtoPorts ()
	{
		return array
		(
			'as the page sends them' => array (array ('localport' => '0', 'remoteport' => '0')),
			'missing' => array (array()),
			'empty' => array (array ('localport' => '', 'remoteport' => '')),
			'not numbers' => array (array ('localport' => 'x', 'remoteport' => "1' OR '1")),
			'numbers of another rule' => array (array ('localport' => '8080', 'remoteport' => '80')),
		);
	}

	/**
	 * @group small
	 * @dataProvider providerAllProtoPorts
	 */
	public function testAllProtocols ($ports)
	{
		$this->setRequest (array ('proto' => 'TCP', 'localport' => '8080', 'remoteport' => '80', 'description' => 'tcp'));
		addPortForwarding();
		$this->setRequest ($ports + array ('proto' => 'ALL', 'description' => 'all'));
		addPortForwarding();
		$this->assertSame (array ('ALL 0 0 all', 'TCP 8080 80 tcp'), $this->rules());

		$this->setRequest ($ports + array ('proto' => 'ALL', 'description' => 'all 2'));
		updPortForwarding();
		$this->assertSame (array ('ALL 0 0 all 2', 'TCP 8080 80 tcp'), $this->rules());

		$this->setRequest ($ports + array ('proto' => 'ALL'));
		delPortForwarding();
		$this->assertSame (array ('TCP 8080 80 tcp'), $this->rules());
	}

	// The form that adds a rule takes an empty remote port for the local one;
	// a rule is still updated and deleted by both of its ports.
	/**
	 * @group small
	 */
	public function testRemotePortDefault ()
	{
		$this->setRequest (array ('proto' => 'TCP', 'localport' => '8080', 'remoteport' => '', 'description' => 'tcp'));
		addPortForwarding();
		$this->setRequest (array ('proto' => 'UDP', 'localport' => '53', 'description' => 'udp'));
		addPortForwarding();
		$this->assertSame (array ('UDP 53 53 udp', 'TCP 8080 8080 tcp'), $this->rules());
		foreach (array ('updPortForwarding', 'delPortForwarding') as $handler)
		{
			$this->setRequest (array ('proto' => 'TCP', 'localport' => '8080', 'remoteport' => '', 'description' => 'changed'));
			try
			{
				$handler();
				$this->fail ("{$handler} accepted an empty remote port");
			}
			catch (InvalidRequestArgException $e)
			{
			}
		}
		$this->assertSame (array ('UDP 53 53 udp', 'TCP 8080 8080 tcp'), $this->rules());
	}

	public function providerBadPorts ()
	{
		return array
		(
			'local port missing' => array (array ('remoteport' => '80')),
			'local port empty' => array (array ('localport' => '', 'remoteport' => '80')),
			'zero' => array (array ('localport' => '0', 'remoteport' => '80')),
			'not a number' => array (array ('localport' => '8080', 'remoteport' => 'x')),
		);
	}

	// With any other protocol the ports are still required.
	/**
	 * @group small
	 * @dataProvider providerBadPorts
	 */
	public function testPortsRequired ($ports)
	{
		$this->setRequest (array ('proto' => 'TCP', 'localport' => '8080', 'remoteport' => '80', 'description' => 'tcp'));
		addPortForwarding();
		foreach (array ('addPortForwarding', 'updPortForwarding', 'delPortForwarding') as $handler)
		{
			$this->setRequest ($ports + array ('proto' => 'TCP', 'description' => 'changed'));
			try
			{
				$handler();
				$this->fail ("{$handler} accepted the ports");
			}
			catch (InvalidRequestArgException $e)
			{
			}
			$this->assertSame (array ('TCP 8080 80 tcp'), $this->rules(), $handler);
		}
	}
}
