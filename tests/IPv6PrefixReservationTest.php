<?php

// Automatic Subnet-Router anycast reservation of a "connected" IPv6 network.
class IPv6PrefixReservationTest extends RTTestCase
{
	protected $net_id = NULL;
	protected $addrlist = array();

	public function tearDown() : void
	{
		if ($this->net_id !== NULL)
			destroyIPv6Prefix ($this->net_id);
		// destroyIPv6Prefix() leaves IPv6Address rows behind
		foreach ($this->addrlist as $ip)
			updateV6Address (ip6_parse ($ip), '', 'no', '');
	}

	/**
	 * @group small
	 * @dataProvider providerReservation
	 */
	public function testReservation ($prefix, $is_connected, $addrlist, $reserved_ip)
	{
		$this->addrlist = $addrlist;
		$this->net_id = createIPv6Prefix ($prefix, $this->myString ($prefix), $is_connected);
		foreach ($addrlist as $ip)
		{
			$addr = getIPv6Address (ip6_parse ($ip));
			if ($ip === $reserved_ip)
			{
				$this->assertEquals ('yes', $addr['reserved'], $ip);
				$this->assertEquals ('Subnet-Router anycast', $addr['name'], $ip);
			}
			else
			{
				$this->assertEquals ('no', $addr['reserved'], $ip);
				$this->assertEquals ('', $addr['name'], $ip);
			}
		}
	}

	public function providerReservation ()
	{
		return array
		(
			array ('2001:db8:31::/64', FALSE, array ('2001:db8:31::', '2001:db8:31::1'), NULL),
			array ('2001:db8:31::/64', TRUE, array ('2001:db8:31::', '2001:db8:31::1'), '2001:db8:31::'),
			// RFC 6164: no Subnet-Router anycast address on a /127
			array ('2001:db8:31:1::/127', FALSE, array ('2001:db8:31:1::', '2001:db8:31:1::1'), NULL),
			array ('2001:db8:31:1::/127', TRUE, array ('2001:db8:31:1::', '2001:db8:31:1::1'), NULL),
			// a /128 is a single host address
			array ('2001:db8:31:2::5/128', FALSE, array ('2001:db8:31:2::5'), NULL),
			array ('2001:db8:31:2::5/128', TRUE, array ('2001:db8:31:2::5'), NULL),
		);
	}
}
