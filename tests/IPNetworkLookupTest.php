<?php

// Finding the network of an IP address and the parent network of a network.
class IPNetworkLookupTest extends RTTestCase
{
	protected $net4_24, $net4_32, $net4_lone32, $net6_64, $net6_128, $net6_lone128;

	public function setUp() : void
	{
		$this->net4_24 = createIPv4Prefix ('198.51.100.0/24', $this->myString ('v4 /24'));
		$this->net4_32 = createIPv4Prefix ('198.51.100.7/32', $this->myString ('v4 /32 in /24'));
		$this->net4_lone32 = createIPv4Prefix ('192.0.2.1/32', $this->myString ('v4 lone /32'));
		$this->net6_64 = createIPv6Prefix ('2001:db8:1::/64', $this->myString ('v6 /64'));
		$this->net6_128 = createIPv6Prefix ('2001:db8:1::7/128', $this->myString ('v6 /128 in /64'));
		$this->net6_lone128 = createIPv6Prefix ('2001:db8::1/128', $this->myString ('v6 lone /128'));
	}

	public function tearDown() : void
	{
		destroyIPv4Prefix ($this->net4_24);
		destroyIPv4Prefix ($this->net4_32);
		destroyIPv4Prefix ($this->net4_lone32);
		destroyIPv6Prefix ($this->net6_64);
		destroyIPv6Prefix ($this->net6_128);
		destroyIPv6Prefix ($this->net6_lone128);
	}

	/**
	 * @group small
	 * @dataProvider providerAddressNetwork
	 */
	public function testAddressNetwork ($ip, $netkey)
	{
		$expected = $netkey === NULL ? NULL : $this->$netkey;
		$ip_bin = ip_parse ($ip);
		$this->assertEquals ($expected, getIPAddressNetworkId ($ip_bin));
		$row = fetchIPAddressNetworkRow ($ip_bin);
		$this->assertEquals ($expected, $row === NULL ? NULL : $row['id']);
		$net = spotNetworkByIP ($ip_bin);
		$this->assertEquals ($expected, $net === NULL ? NULL : $net['id']);
		if (strlen ($ip_bin) == 4)
			$this->assertEquals ($expected, getIPv4AddressNetworkId ($ip_bin));
		else
			$this->assertEquals ($expected, getIPv6AddressNetworkId ($ip_bin));
	}

	/**
	 * @group small
	 * @dataProvider providerParentNetwork
	 */
	public function testParentNetwork ($ip, $mask, $netkey)
	{
		$expected = $netkey === NULL ? NULL : $this->$netkey;
		$this->assertEquals ($expected, getIPAddressNetworkId (ip_parse ($ip), $mask));
	}

	public function providerAddressNetwork ()
	{
		return array
		(
			array ('198.51.100.6', 'net4_24'),
			array ('198.51.100.7', 'net4_32'),
			array ('192.0.2.1', 'net4_lone32'),
			array ('192.0.2.2', NULL),
			array ('2001:db8:1::6', 'net6_64'),
			array ('2001:db8:1::7', 'net6_128'),
			array ('2001:db8::1', 'net6_lone128'),
			array ('2001:db8::2', NULL),
		);
	}

	public function providerParentNetwork ()
	{
		return array
		(
			array ('198.51.100.7', 32, 'net4_24'),
			array ('198.51.100.0', 24, NULL),
			array ('192.0.2.1', 32, NULL),
			array ('2001:db8:1::7', 128, 'net6_64'),
			array ('2001:db8:1::', 64, NULL),
			array ('2001:db8::1', 128, NULL),
		);
	}
}
