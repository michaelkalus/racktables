<?php

// Deleting an IP network that contains used addresses: allowed only when
// another network contains it, so that the addresses stay in a network.
class IPNetworkDeleteTest extends RTTestCase
{
	protected static $object_id;
	protected $net_ids = array ('ipv4net' => array(), 'ipv6net' => array());
	protected $saved_jaywalk;
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
		$this->saved_jaywalk = getConfigVar ('IPV4_JAYWALK');
		setConfigVar ('IPV4_JAYWALK', 'no');
		$this->saved_auto_release = getConfigVar ('IPV4_AUTO_RELEASE');
		setMessageBuffering (TRUE);
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $tabno, $log_messages;
		setConfigVar ('IPV4_JAYWALK', $this->saved_jaywalk);
		setConfigVar ('IPV4_AUTO_RELEASE', $this->saved_auto_release);
		setMessageBuffering (FALSE);
		$log_messages = array();
		unset ($sic['id'], $_REQUEST['id']);
		$pageno = 'index';
		$tabno = 'default';
		foreach ($this->net_ids['ipv4net'] as $id)
			usePreparedDeleteBlade ('IPv4Network', array ('id' => $id));
		foreach ($this->net_ids['ipv6net'] as $id)
			usePreparedDeleteBlade ('IPv6Network', array ('id' => $id));
		usePreparedDeleteBlade ('IPv4Allocation', array ('object_id' => self::$object_id));
		usePreparedDeleteBlade ('IPv6Allocation', array ('object_id' => self::$object_id));
		// destroyIPv4Prefix() and destroyIPv6Prefix() leave address records behind
		usePreparedExecuteBlade
		(
			'DELETE FROM IPv4Address WHERE ip BETWEEN ? AND ? OR ip BETWEEN ? AND ?',
			array
			(
				ip4_bin2db (ip4_parse ('192.0.2.0')), ip4_bin2db (ip4_parse ('192.0.2.255')),
				ip4_bin2db (ip4_parse ('198.51.100.0')), ip4_bin2db (ip4_parse ('198.51.100.255')),
			)
		);
		usePreparedExecuteBlade
		(
			'DELETE FROM IPv6Address WHERE ip BETWEEN ? AND ?',
			array (ip6_parse ('2001:db8:90::'), ip6_parse ('2001:db8:91:ffff:ffff:ffff:ffff:ffff'))
		);
	}

	protected function createNet ($prefix, $addrlist)
	{
		if (FALSE !== strpos ($prefix, ':'))
		{
			$realm = 'ipv6net';
			$id = createIPv6Prefix ($prefix, $this->myString ($prefix), TRUE);
		}
		else
		{
			$realm = 'ipv4net';
			$id = createIPv4Prefix ($prefix, $this->myString ($prefix), TRUE);
		}
		$this->net_ids[$realm][] = $id;
		foreach ($addrlist as $ip => $what)
			if ($what === 'alloc')
				bindIPToObject (ip_parse ($ip), self::$object_id, 'eth0', 'regular');
			elseif (is_array ($what)) // name, reserved, comment
				updateAddress (ip_parse ($ip), $what[0], $what[1], $what[2]);
			else
				updateAddress (ip_parse ($ip), $what, 'yes', '');
		return $id;
	}

	protected function netExists ($realm, $id)
	{
		$table = $realm == 'ipv4net' ? 'IPv4Network' : 'IPv6Network';
		$result = usePreparedSelectBlade ("SELECT COUNT(*) FROM {$table} WHERE id = ?", array ($id));
		return $result->fetchColumn() == 1;
	}

	// run the ophandler the way index.php?module=redirect does
	protected function runDelete ($realm, $id)
	{
		global $sic, $pageno, $tabno;
		$pageno = $realm;
		$tabno = 'properties';
		$sic['id'] = $_REQUEST['id'] = $id;
		call_user_func ($realm == 'ipv4net' ? 'delIPv4Prefix' : 'delIPv6Prefix');
		return ! $this->netExists ($realm, $id);
	}

	// TRUE if the properties tab of the network and the "manage" tab of the
	// address space both offer a working delete link, FALSE if neither does
	protected function pageOffersDelete ($realm, $id)
	{
		global $pageno, $tabno, $entityCache;
		$entityCache = array();
		$pageno = $realm;
		$tabno = 'properties';
		ob_start();
		renderIPNetworkProperties ($id);
		$properties = FALSE !== strpos (ob_get_clean(), 'op=del');
		$pageno = $realm == 'ipv4net' ? 'ipv4space' : 'ipv6space';
		$tabno = 'manage';
		ob_start();
		renderIPSpaceEditor();
		$manage = FALSE !== strpos (ob_get_clean(), "op=del&id={$id}\"");
		$this->assertEquals ($properties, $manage);
		return $properties;
	}

	protected function assertAddress ($ip, $name, $reserved, $alloc_count, $net_id, $comment = '')
	{
		$addr = getIPAddress (ip_parse ($ip));
		$this->assertEquals ($comment, $addr['comment'], $ip);
		$this->assertEquals ($name, $addr['name'], $ip);
		$this->assertEquals ($reserved, $addr['reserved'], $ip);
		$this->assertCount ($alloc_count, $addr['allocs'], $ip);
		$this->assertEquals ($net_id, getIPAddressNetworkId (ip_parse ($ip)), $ip);
	}

	/**
	 * @group small
	 */
	public function testIPv4NoContainingNetwork ()
	{
		$id = $this->createNet ('192.0.2.16/28', array ('192.0.2.17' => 'alloc'));
		$this->assertFalse ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertFalse ($this->runDelete ('ipv4net', $id));
		$this->assertAddress ('192.0.2.16', 'network', 'yes', 0, $id);
		$this->assertAddress ('192.0.2.17', '', 'no', 1, $id);
	}

	/**
	 * @group small
	 */
	public function testIPv4EmptyNoContainingNetwork ()
	{
		$id = $this->createNet ('192.0.2.16/28', array ('192.0.2.16' => 'network', '192.0.2.31' => 'broadcast'));
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		$this->assertAddress ('192.0.2.16', '', 'no', 0, NULL);
		$this->assertAddress ('192.0.2.31', '', 'no', 0, NULL);
	}

	/**
	 * @group small
	 */
	public function testIPv4ContainingNetwork ()
	{
		$outer = $this->createNet ('198.51.100.0/24', array());
		$id = $this->createNet ('198.51.100.16/28', array
		(
			'198.51.100.17' => 'alloc',
			'198.51.100.20' => 'user reservation',
			// a user reservation on the broadcast address of the inner network
			'198.51.100.31' => 'user broadcast',
		));
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		// the automatic reservation of the deleted network is gone
		$this->assertAddress ('198.51.100.16', '', 'no', 0, $outer);
		// everything else stays and now belongs to the containing network
		$this->assertAddress ('198.51.100.17', '', 'no', 1, $outer);
		$this->assertAddress ('198.51.100.20', 'user reservation', 'yes', 0, $outer);
		$this->assertAddress ('198.51.100.31', 'user broadcast', 'yes', 0, $outer);
		$this->assertAddress ('198.51.100.0', 'network', 'yes', 0, $outer);
		$this->assertAddress ('198.51.100.255', 'broadcast', 'yes', 0, $outer);
	}

	/**
	 * @group small
	 */
	public function testIPv4SharedNetworkAddress ()
	{
		$outer = $this->createNet ('198.51.100.0/24', array());
		$id = $this->createNet ('198.51.100.0/28', array ('198.51.100.1' => 'alloc'));
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		// 198.51.100.0 is the network address of the containing network too
		$this->assertAddress ('198.51.100.0', 'network', 'yes', 0, $outer);
		$this->assertAddress ('198.51.100.1', '', 'no', 1, $outer);
		$this->assertAddress ('198.51.100.15', '', 'no', 0, $outer);
	}

	/**
	 * @group small
	 */
	public function testIPv6NoContainingNetwork ()
	{
		$id = $this->createNet ('2001:db8:91::/64', array ('2001:db8:91::5' => 'alloc'));
		$this->assertFalse ($this->pageOffersDelete ('ipv6net', $id));
		$this->assertFalse ($this->runDelete ('ipv6net', $id));
		$this->assertAddress ('2001:db8:91::', 'Subnet-Router anycast', 'yes', 0, $id);
		$this->assertAddress ('2001:db8:91::5', '', 'no', 1, $id);
	}

	/**
	 * @group small
	 */
	public function testIPv6ContainingNetwork ()
	{
		$outer = $this->createNet ('2001:db8:90::/48', array());
		$id = $this->createNet ('2001:db8:90:1::/64', array
		(
			'2001:db8:90:1::5' => 'alloc',
			'2001:db8:90:1::6' => 'user reservation',
		));
		$this->assertTrue ($this->pageOffersDelete ('ipv6net', $id));
		$this->assertTrue ($this->runDelete ('ipv6net', $id));
		$this->assertAddress ('2001:db8:90:1::', '', 'no', 0, $outer);
		$this->assertAddress ('2001:db8:90:1::5', '', 'no', 1, $outer);
		$this->assertAddress ('2001:db8:90:1::6', 'user reservation', 'yes', 0, $outer);
		$this->assertAddress ('2001:db8:90::', 'Subnet-Router anycast', 'yes', 0, $outer);
	}

	/**
	 * @group small
	 */
	public function testIPv6SharedAnycastAddress ()
	{
		$outer = $this->createNet ('2001:db8:90::/48', array());
		$id = $this->createNet ('2001:db8:90::/64', array ('2001:db8:90::5' => 'alloc'));
		$this->assertTrue ($this->pageOffersDelete ('ipv6net', $id));
		$this->assertTrue ($this->runDelete ('ipv6net', $id));
		$this->assertAddress ('2001:db8:90::', 'Subnet-Router anycast', 'yes', 0, $outer);
		$this->assertAddress ('2001:db8:90::5', '', 'no', 1, $outer);
	}

	/**
	 * @group small
	 */
	public function testJaywalkKeepsUserReservation ()
	{
		setConfigVar ('IPV4_JAYWALK', 'yes');
		$id = $this->createNet ('192.0.2.16/28', array ('192.0.2.17' => 'alloc', '192.0.2.31' => 'user broadcast'));
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		$this->assertAddress ('192.0.2.16', '', 'no', 0, NULL);
		$this->assertAddress ('192.0.2.17', '', 'no', 1, NULL);
		$this->assertAddress ('192.0.2.31', 'user broadcast', 'yes', 0, NULL);
	}

	/**
	 * @group small
	 */
	public function testIPv4AutoEndpointWithComment ()
	{
		$outer = $this->createNet ('198.51.100.0/24', array());
		$id = $this->createNet ('198.51.100.16/28', array
		(
			'198.51.100.16' => array ('network', 'yes', 'network comment'),
			'198.51.100.31' => array ('broadcast', 'yes', 'broadcast comment'),
		));
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		// the automatic name and flag go, the comment stays
		$this->assertAddress ('198.51.100.16', '', 'no', 0, $outer, 'network comment');
		$this->assertAddress ('198.51.100.31', '', 'no', 0, $outer, 'broadcast comment');
	}

	/**
	 * @group small
	 * @dataProvider providerAutoRelease
	 */
	public function testIPv4AutoEndpointWithAllocation ($auto_release)
	{
		// IPV4_AUTO_RELEASE 1 (default) clears the reserved flag on allocation,
		// 0 leaves the address reserved
		setConfigVar ('IPV4_AUTO_RELEASE', $auto_release);
		$outer = $this->createNet ('198.51.100.0/24', array());
		$id = $this->createNet ('198.51.100.16/28', array ('198.51.100.16' => 'alloc', '198.51.100.31' => 'alloc'));
		$this->assertAddress ('198.51.100.16', 'network', $auto_release ? 'no' : 'yes', 1, $id);
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		$this->assertAddress ('198.51.100.16', '', 'no', 1, $outer);
		$this->assertAddress ('198.51.100.31', '', 'no', 1, $outer);
	}

	public function providerAutoRelease ()
	{
		return array (array ('0'), array ('1'));
	}

	/**
	 * @group small
	 */
	public function testIPv4UserEndpointWithComment ()
	{
		$outer = $this->createNet ('198.51.100.0/24', array());
		$id = $this->createNet ('198.51.100.16/28', array
		(
			'198.51.100.16' => array ('user network', 'yes', 'user comment'),
			'198.51.100.31' => array ('user broadcast', 'no', 'user comment'),
		));
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		$this->assertAddress ('198.51.100.16', 'user network', 'yes', 0, $outer, 'user comment');
		$this->assertAddress ('198.51.100.31', 'user broadcast', 'no', 0, $outer, 'user comment');
	}

	/**
	 * @group small
	 */
	public function testIPv4SharedEndpointWithComment ()
	{
		$outer = $this->createNet ('198.51.100.0/24', array());
		$id = $this->createNet ('198.51.100.0/28', array ('198.51.100.0' => array ('network', 'yes', 'shared comment')));
		$this->assertTrue ($this->pageOffersDelete ('ipv4net', $id));
		$this->assertTrue ($this->runDelete ('ipv4net', $id));
		$this->assertAddress ('198.51.100.0', 'network', 'yes', 0, $outer, 'shared comment');
		$this->assertAddress ('198.51.100.15', '', 'no', 0, $outer);
	}

	/**
	 * @group small
	 */
	public function testIPv6AnycastWithComment ()
	{
		$outer = $this->createNet ('2001:db8:90::/48', array());
		$id = $this->createNet ('2001:db8:90:1::/64', array
		(
			'2001:db8:90:1::' => array ('Subnet-Router anycast', 'yes', 'anycast comment'),
		));
		$this->assertTrue ($this->pageOffersDelete ('ipv6net', $id));
		$this->assertTrue ($this->runDelete ('ipv6net', $id));
		$this->assertAddress ('2001:db8:90:1::', '', 'no', 0, $outer, 'anycast comment');
	}
}
