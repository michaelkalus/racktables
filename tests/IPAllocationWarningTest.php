<?php

// Allocating an address to an object warns about what the allocation does to
// the name, the comment and the reservation of the address, which depends on
// IPV4_AUTO_RELEASE (see bindIPToObject()). The warning must match what
// actually happens.
class IPAllocationWarningTest extends RTTestCase
{
	protected static $object_id;
	protected $net_ids = array ('ipv4net' => array(), 'ipv6net' => array());
	protected $saved_auto_release;

	public static function setUpBeforeClass () : void
	{
		self::$object_id = commitAddObject (self::myStringStatic ('object', __CLASS__), NULL, 4, NULL);
	}

	public static function tearDownAfterClass () : void
	{
		commitDeleteObject (self::$object_id);
	}

	public function setUp () : void
	{
		$this->saved_auto_release = getConfigVar ('IPV4_AUTO_RELEASE');
		setMessageBuffering (TRUE);
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $tabno, $op, $log_messages;
		setConfigVar ('IPV4_AUTO_RELEASE', $this->saved_auto_release);
		setMessageBuffering (FALSE);
		$log_messages = array();
		foreach (array ('object_id', 'ip', 'bond_name', 'bond_type') as $argname)
			unset ($sic[$argname], $_REQUEST[$argname]);
		$pageno = 'index';
		$tabno = 'default';
		$op = '';
		usePreparedDeleteBlade ('IPv4Allocation', array ('object_id' => self::$object_id));
		usePreparedDeleteBlade ('IPv6Allocation', array ('object_id' => self::$object_id));
		foreach ($this->net_ids['ipv4net'] as $id)
			destroyIPv4Prefix ($id);
		foreach ($this->net_ids['ipv6net'] as $id)
			destroyIPv6Prefix ($id);
		// destroyIPv4Prefix() and destroyIPv6Prefix() leave address records behind
		usePreparedExecuteBlade
		(
			'DELETE FROM IPv4Address WHERE ip BETWEEN ? AND ?',
			array (ip4_bin2db (ip4_parse ('192.0.2.0')), ip4_bin2db (ip4_parse ('192.0.2.255')))
		);
		usePreparedExecuteBlade
		(
			'DELETE FROM IPv6Address WHERE ip BETWEEN ? AND ?',
			array (ip6_parse ('2001:db8:92::'), ip6_parse ('2001:db8:92::ffff'))
		);
	}

	// run the ophandler the way index.php?module=redirect does
	protected function allocate ($ip)
	{
		global $sic, $ophandler, $pageno, $tabno, $op, $log_messages;
		$log_messages = array();
		$pageno = 'object';
		$tabno = 'ip';
		$op = 'add';
		$args = array
		(
			'object_id' => self::$object_id,
			'ip' => $ip,
			'bond_name' => 'eth0',
			'bond_type' => 'regular',
		);
		foreach ($args as $argname => $argvalue)
			$sic[$argname] = $_REQUEST[$argname] = $argvalue;
		call_user_func ($ophandler[$pageno][$tabno][$op]);
		$ret = array();
		foreach ($log_messages as $line)
			if ($line['c'] == 200)
				$ret[] = $line['a'][0];
		return $ret;
	}

	public function providerAllocation ()
	{
		$ret = array();
		foreach (array ('192.0.2.10', '2001:db8:92::10') as $ip)
		{
			// address name, reserved, comment, IPV4_AUTO_RELEASE,
			// expected warnings, expected name, reserved, comment after
			$ret[] = array ($ip, 'named', 'no', '', '0', array(), 'named', 'no', '');
			$ret[] = array ($ip, 'named', 'no', '', '1', array(), 'named', 'no', '');
			$ret[] = array ($ip, 'named', 'no', 'note', '2', array ("IP {$ip} name \"named\" and comment are removed"), '', 'no', '');
			$ret[] = array ($ip, 'resv', 'yes', '', '0', array ("IP {$ip} reservation \"resv\" is kept"), 'resv', 'yes', '');
			$ret[] = array ($ip, 'resv', 'yes', 'note', '1', array ("IP {$ip} reservation \"resv\" is removed, the name is kept"), 'resv', 'no', 'note');
			$ret[] = array ($ip, 'resv', 'yes', '', '2', array ("IP {$ip} reservation and name \"resv\" are removed"), '', 'no', '');
			$ret[] = array ($ip, '', 'yes', '', '1', array ("IP {$ip} reservation is removed"), '', 'no', '');
			$ret[] = array ($ip, '', 'no', '', '2', array(), '', 'no', '');
		}
		return $ret;
	}

	/**
	 * @group small
	 * @dataProvider providerAllocation
	 */
	public function testAllocationWarning ($ip, $name, $reserved, $comment, $auto_release, $warnings, $name_after, $reserved_after, $comment_after)
	{
		if (FALSE !== strpos ($ip, ':'))
			$this->net_ids['ipv6net'][] = createIPv6Prefix ('2001:db8:92::/64', $this->myString ('net'), FALSE);
		else
			$this->net_ids['ipv4net'][] = createIPv4Prefix ('192.0.2.0/24', $this->myString ('net'), FALSE);
		if ($name != '' || $reserved != 'no' || $comment != '')
			updateAddress (ip_parse ($ip), $name, $reserved, $comment);
		setConfigVar ('IPV4_AUTO_RELEASE', $auto_release);
		$this->assertEquals ($warnings, $this->allocate ($ip));
		$addr = getIPAddress (ip_parse ($ip));
		$this->assertEquals ($name_after, $addr['name']);
		$this->assertEquals ($reserved_after, $addr['reserved']);
		$this->assertEquals ($comment_after, $addr['comment']);
		$this->assertCount (1, $addr['allocs']);
	}
}
