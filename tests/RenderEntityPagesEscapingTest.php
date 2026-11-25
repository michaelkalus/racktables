<?php

// Make sure entity pages escape stored text values they print.
class RenderEntityPagesEscapingTest extends RTTestCase
{
	const RAW = '<i>probe</i>"\'&';
	const ESCAPED = '&lt;i&gt;probe&lt;/i&gt;&quot;&#039;&amp;';

	protected $objects = array();
	protected $nets4 = array();
	protected $nets6 = array();
	protected $attr_id = NULL;
	protected $attr7_name = NULL;
	protected $vs_id = NULL;
	protected $pool_id = NULL;
	protected $user_id = NULL;
	protected $chapter_id = NULL;
	protected $vdom_id = NULL;
	protected $vst_id = NULL;
	protected $saved_request = NULL;
	protected $saved_sic = NULL;
	protected $saved_auto_tags = NULL;

	public function setUp () : void
	{
		global $sic, $auto_tags;
		$this->saved_request = $_REQUEST;
		$this->saved_sic = $sic;
		$this->saved_auto_tags = $auto_tags;
		// The default RackCode allows everything to the admin account.
		$auto_tags[] = array ('tag' => '$userid_1');
	}

	public function tearDown () : void
	{
		global $sic, $auto_tags, $object_attribute_cache;
		$_REQUEST = $this->saved_request;
		$sic = $this->saved_sic;
		$auto_tags = $this->saved_auto_tags;
		$object_attribute_cache = array();
		foreach (array_reverse ($this->objects) as $object_id)
		{
			usePreparedDeleteBlade ('VLANSwitch', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('RackSpace', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $object_id));
			commitDeleteObject ($object_id);
		}
		if ($this->vs_id !== NULL)
		{
			usePreparedDeleteBlade ('VSIPs', array ('vs_id' => $this->vs_id));
			commitDeleteVSG ($this->vs_id);
		}
		foreach ($this->nets4 as $net_id)
			destroyIPv4Prefix ($net_id);
		foreach ($this->nets6 as $net_id)
			destroyIPv6Prefix ($net_id);
		usePreparedDeleteBlade ('IPv4Address', array ('ip' => ip4_parse ('198.51.100.5')));
		usePreparedDeleteBlade ('IPv6Address', array ('ip' => ip6_parse ('2001:db8:5::5')));
		if ($this->attr_id !== NULL)
		{
			usePreparedDeleteBlade ('AttributeMap', array ('attr_id' => $this->attr_id));
			usePreparedDeleteBlade ('Attribute', array ('id' => $this->attr_id));
		}
		if ($this->attr7_name !== NULL)
			usePreparedUpdateBlade ('Attribute', array ('name' => $this->attr7_name), array ('id' => 7));
		if ($this->pool_id !== NULL)
			commitDeleteRSPool ($this->pool_id);
		if ($this->user_id !== NULL)
			commitDeleteUserAccount ($this->user_id);
		if ($this->chapter_id !== NULL)
			usePreparedDeleteBlade ('Chapter', array ('id' => $this->chapter_id));
		if ($this->vst_id !== NULL)
			usePreparedDeleteBlade ('VLANSwitchTemplate', array ('id' => $this->vst_id));
		if ($this->vdom_id !== NULL)
		{
			usePreparedDeleteBlade ('VLANDescription', array ('domain_id' => $this->vdom_id));
			usePreparedDeleteBlade ('VLANDomain', array ('id' => $this->vdom_id));
		}
	}

	protected function addObject ($name, $type_id)
	{
		$object_id = commitAddObject ($name, NULL, $type_id, NULL);
		$this->objects[] = $object_id;
		return $object_id;
	}

	protected function setArg ($name, $value)
	{
		global $sic;
		$sic[$name] = $value;
		$_REQUEST[$name] = htmlspecialchars ($value, ENT_QUOTES, 'UTF-8');
	}

	protected function assertEscaped ($before, $after, $output)
	{
		$this->assertStringContainsString ($before . self::ESCAPED . $after, $output);
		$this->assertStringNotContainsString ($before . self::RAW, $output);
	}

	// An element that portinfo.js reads a port name from must carry the
	// stored name in data-portname and display it escaped.
	protected function assertInteractivePortName ($output)
	{
		$this->assertMatchesRegularExpression
		(
			"/class='interactive-portname[^>]* data-portname='" . preg_quote (self::ESCAPED, '/') . "'[^>]*>" .
			preg_quote (self::ESCAPED, '/') . '<\\/a>/',
			$output
		);
		$this->assertStringNotContainsString ('>' . self::RAW, $output);
	}

	// attribute name in the summary portlet of an object, rack, row and location
	/**
	 * @group small
	 */
	public function testStickerName ()
	{
		usePreparedInsertBlade ('Attribute', array ('type' => 'string', 'name' => self::RAW));
		$this->attr_id = lastInsertID();
		$object_id = $this->addObject ($this->myString ('server'), 4);
		$rack_id = $this->addObject ($this->myString ('rack'), 1560);
		$row_id = $this->addObject ($this->myString ('row'), 1561);
		$location_id = $this->addObject ($this->myString ('location'), 1562);
		foreach (array ($object_id => 4, $rack_id => 1560, $row_id => 1561, $location_id => 1562) as $id => $type_id)
		{
			commitSupplementAttrMap ($this->attr_id, $type_id);
			commitUpdateAttrValue ($id, $this->attr_id, 'value');
		}
		commitUpdateAttrValue ($rack_id, 27, 42);
		commitLinkEntities ('row', $row_id, 'rack', $rack_id);
		$before = "sticker'>";
		$this->assertEscaped ($before, ':</th>', getOutputOf ('renderObject', $object_id));
		$rack = spotEntity ('rack', $rack_id);
		amplifyCell ($rack);
		$this->assertEscaped ($before, ':</th>', getOutputOf ('renderRackInfoPortlet', $rack));
		$this->assertEscaped ($before, ':</th>', getOutputOf ('renderRow', $row_id));
		$this->assertEscaped ($before, ':</th>', getOutputOf ('renderLocationPage', $location_id));
	}

	// name of a power attribute summed up in the rack summary
	/**
	 * @group small
	 */
	public function testRackPowerAttributeName ()
	{
		$attrs = getAttrMap();
		$this->attr7_name = $attrs[7]['name'];
		usePreparedUpdateBlade ('Attribute', array ('name' => self::RAW), array ('id' => 7));
		$row_id = $this->addObject ($this->myString ('row'), 1561);
		$rack_id = $this->addObject ($this->myString ('rack'), 1560);
		$ups_id = $this->addObject ($this->myString ('ups'), 12);
		commitUpdateAttrValue ($rack_id, 27, 42);
		commitLinkEntities ('row', $row_id, 'rack', $rack_id);
		commitUpdateAttrValue ($ups_id, 7, 2.5);
		usePreparedInsertBlade
		(
			'RackSpace',
			array ('rack_id' => $rack_id, 'unit_no' => 1, 'atom' => 'front', 'state' => 'T', 'object_id' => $ups_id)
		);
		$rack = spotEntity ('rack', $rack_id);
		amplifyCell ($rack);
		$this->assertEscaped ("class='tdright'>", ':</th>', getOutputOf ('renderRackInfoPortlet', $rack));
	}

	// port name, label and cable ID on the object page
	/**
	 * @group small
	 */
	public function testObjectPortRow ()
	{
		$port_id = $this->addLinkedPort();
		$output = getOutputOf ('renderObjectPortRow', getPortInfo ($port_id), FALSE);
		$this->assertInteractivePortName ($output);
		$this->assertEscaped ('<td class=tdleft>', '</td>', $output);
		$this->assertEscaped ("op-upd-reservation-cable'>", '</span>', $output);
	}

	// port name, label and cable ID on the ports tab
	// (renderPortsForObject(), renderIPForObject() and renderIPAddressAllocations()
	// declare a nested function of the same name, so only one of them can be
	// called per process)
	/**
	 * @group small
	 */
	public function testPortsForObject ()
	{
		global $pageno, $tabno;
		$port_id = $this->addLinkedPort();
		$object_id = $this->objects[0];
		$pageno = 'object';
		$tabno = 'ports';
		$this->setArg ('object_id', $object_id);
		$output = getOutputOf ('renderPortsForObject', $object_id);
		$this->assertEscaped ("name=name class='interactive-portname port-menu' data-portname='" . self::ESCAPED . "' value='", "'", $output);
		$this->assertEscaped ("name=label value='", "'", $output);
		$this->assertEscaped ("name=cable value='", "'", $output);
	}

	protected function addLinkedPort ()
	{
		$object_id = $this->addObject ($this->myString ('switch'), 8);
		$peer_id = $this->addObject ($this->myString ('server'), 4);
		$port_id = commitAddPort ($object_id, self::RAW, '1-24', self::RAW, '');
		$peer_port_id = commitAddPort ($peer_id, 'peer', '1-24', '', '');
		linkPorts ($port_id, $peer_port_id, self::RAW);
		return $port_id;
	}

	// port name in links to a port, including the interactive ones on the
	// CDP/LLDP neighbors tab
	/**
	 * @group small
	 */
	public function testFormatPortLink ()
	{
		$this->assertEscaped
		(
			"data-portname='" . self::ESCAPED . "' href=\"index.php?page=object&object_id=1&hl_port_id=2\">",
			'</a>',
			formatPortLink (1, NULL, 2, self::RAW)
		);
		$this->assertInteractivePortName (formatPortLink (1, NULL, 2, self::RAW, 'interactive-portname port-menu'));
		// a name stored escaped keeps its stored form in data-portname
		$this->assertStringContainsString
		(
			"data-portname='A&amp;amp;B'",
			formatPortLink (1, NULL, 2, 'A&amp;B', 'interactive-portname port-menu')
		);
	}

	protected function add8021QSwitch ()
	{
		require_once '../wwwroot/inc/interface-8021q.php';
		$object_id = $this->addObject ($this->myString ('switch'), 8);
		commitAddPort ($object_id, self::RAW, '1-24', '', '');
		usePreparedInsertBlade ('VLANDomain', array ('description' => $this->myString ('domain')));
		$this->vdom_id = lastInsertID();
		usePreparedInsertBlade
		(
			'VLANDescription',
			array ('domain_id' => $this->vdom_id, 'vlan_id' => VLAN_DFL_ID, 'vlan_type' => 'compulsory', 'vlan_descr' => 'default')
		);
		usePreparedInsertBlade
		(
			'VLANSwitchTemplate',
			array ('description' => $this->myString ('template'), 'mutex_rev' => 0, 'saved_by' => '')
		);
		$this->vst_id = lastInsertID();
		usePreparedInsertBlade
		(
			'VLANSwitch',
			array ('object_id' => $object_id, 'domain_id' => $this->vdom_id, 'template_id' => $this->vst_id)
		);
		$default_port = array ('mode' => 'access', 'allowed' => array (VLAN_DFL_ID), 'native' => VLAN_DFL_ID);
		add8021QPort ($object_id, self::RAW, $default_port);
		return array ($object_id, $default_port);
	}

	// port name on the 802.1Q ports tab
	/**
	 * @group small
	 */
	public function testObject8021QPorts ()
	{
		global $pageno, $tabno;
		list ($object_id, $default_port) = $this->add8021QSwitch();
		$pageno = 'object';
		$tabno = '8021qports';
		$this->setArg ('object_id', $object_id);
		$this->assertInteractivePortName (getOutputOf ('renderObject8021QPorts', $object_id));
	}

	// port name on the 802.1Q sync tab
	/**
	 * @group small
	 */
	public function testObject8021QSyncPreview ()
	{
		global $pageno, $tabno;
		list ($object_id, $default_port) = $this->add8021QSwitch();
		$pageno = 'object';
		$tabno = '8021qsync';
		$this->setArg ('object_id', $object_id);
		$object = spotEntity ('object', $object_id);
		amplifyCell ($object);
		$plan = array (self::RAW => array ('status' => 'in_sync', 'both' => $default_port));
		$output = getOutputOf
		(
			'renderObject8021QSyncPreview',
			$object,
			getVLANSwitchInfo ($object_id),
			$plan,
			array(),
			array ('portconfig' => array()),
			0
		);
		$this->assertInteractivePortName ($output);
	}

	// OS interface name and object name in a router cell
	/**
	 * @group small
	 */
	public function testRouterCell ()
	{
		list ($object_id, $ip_bin) = $this->addAllocation();
		$output = getOutputOf ('renderRouterCell', $ip_bin, self::RAW, spotEntity ('object', $object_id));
		$this->assertEscaped ('198.51.100.10@', '</td>', $output);
		$this->assertEscaped ('<strong>', '</strong>', $output);
	}

	protected function addAllocation ()
	{
		$this->nets4[] = createIPv4Prefix ('198.51.100.0/24', $this->myString ('net'));
		$object_id = $this->addObject (self::RAW, 4);
		$ip_bin = ip4_parse ('198.51.100.10');
		bindIPToObject ($ip_bin, $object_id, self::RAW, 'router');
		return array ($object_id, $ip_bin);
	}

	// IP address name and comment, VS group name on network pages and the address page
	/**
	 * @group small
	 */
	public function testAddressNameAndComment ()
	{
		global $pageno, $tabno;
		$net4_id = createIPv4Prefix ('198.51.100.0/24', $this->myString ('net'));
		$this->nets4[] = $net4_id;
		$net6_id = createIPv6Prefix ('2001:db8:5::/64', $this->myString ('net'));
		$this->nets6[] = $net6_id;
		$ip4_bin = ip4_parse ('198.51.100.5');
		$ip6_bin = ip6_parse ('2001:db8:5::5');
		updateAddress ($ip4_bin, self::RAW, 'no', self::RAW);
		updateAddress ($ip6_bin, self::RAW, 'no', self::RAW);
		usePreparedInsertBlade ('VS', array ('name' => self::RAW));
		$this->vs_id = lastInsertID();
		usePreparedInsertBlade ('VSIPs', array ('vs_id' => $this->vs_id, 'vip' => ip4_parse ('198.51.100.6')));
		usePreparedInsertBlade ('VSIPs', array ('vs_id' => $this->vs_id, 'vip' => ip6_parse ('2001:db8:5::6')));

		foreach (array ('ipv4net' => $net4_id, 'ipv6net' => $net6_id) as $realm => $net_id)
		{
			$pageno = $realm;
			$tabno = 'default';
			$this->setArg ('id', $net_id);
			$range = spotEntity ($realm, $net_id);
			loadIPAddrList ($range);
			$output = getOutputOf ('renderIPNetworkAddresses', $range);
			$this->assertEscaped ("op-upd-ip-name'>", '</span>', $output);
			$this->assertEscaped ("op-upd-ip-comment'>", '</span>', $output);
			$this->assertEscaped ('vs_id=' . $this->vs_id . '">', '</a>', $output);
		}

		$pageno = 'ipaddress';
		$tabno = 'default';
		foreach (array ('198.51.100.5' => $ip4_bin, '2001:db8:5::5' => $ip6_bin) as $ip => $ip_bin)
		{
			$this->setArg ('ip', $ip);
			$output = getOutputOf ('renderIPAddress', $ip_bin);
			$this->assertEscaped ('Name:</th><td class=tdleft>', '</td>', $output);
			$this->assertEscaped ('Comment:</th><td class=tdleft>', '</td>', $output);
		}
	}

	// VS group and RS pool page headings
	/**
	 * @group small
	 */
	public function testSLBHeadings ()
	{
		global $pageno, $tabno;
		usePreparedInsertBlade ('VS', array ('name' => self::RAW));
		$this->vs_id = lastInsertID();
		$this->pool_id = commitCreateRSPool (self::RAW);

		$pageno = 'ipvs';
		$tabno = 'default';
		$this->setArg ('vs_id', $this->vs_id);
		$this->assertEscaped ('<h1>', '</h1>', getOutputOf ('renderVS', $this->vs_id));

		$pageno = 'ipv4rspool';
		$this->setArg ('pool_id', $this->pool_id);
		$this->assertEscaped ('<h1>', '</h1>', getOutputOf ('renderRSPool', $this->pool_id));
	}

	// page title and breadcrumb names
	/**
	 * @group small
	 */
	public function testDynamicTitles ()
	{
		global $pageno;
		usePreparedInsertBlade ('VS', array ('name' => self::RAW));
		$this->vs_id = lastInsertID();
		$this->pool_id = commitCreateRSPool (self::RAW);
		$this->user_id = commitCreateUserAccount (self::RAW, '', sha1 ('x'));
		usePreparedInsertBlade ('Chapter', array ('name' => self::RAW));
		$this->chapter_id = lastInsertID();
		$object_id = $this->addObject (self::RAW, 4);

		$cases = array
		(
			array ('ipvs', 'vs_id', $this->vs_id, '', ''),
			array ('ipv4rspool', 'pool_id', $this->pool_id, '', ''),
			array ('user', 'user_id', $this->user_id, "Local user '", "'"),
			array ('chapter', 'chapter_no', $this->chapter_id, "Chapter '", "'"),
			array ('object', 'object_id', $object_id, '', ''),
		);
		foreach ($cases as $case)
		{
			list ($pageno, $argname, $id, $prefix, $suffix) = $case;
			$this->setArg ($argname, $id);
			$title = dynamic_title_decoder ($pageno);
			$this->assertSame ($prefix . self::ESCAPED . $suffix, $title['name'], $pageno);
			$this->assertSame ($prefix . self::ESCAPED . $suffix, getTitle ($pageno), $pageno);
		}
	}

	// OEM S/N in the warranty expiration report
	/**
	 * @group small
	 */
	public function testExpirationReport ()
	{
		global $interface_requires;
		require_once '../wwwroot/inc/navigation.php';
		requireExtraFiles ($interface_requires, 'reports', 'warranty');
		$object_id = $this->addObject ($this->myString ('server'), 4);
		commitUpdateAttrValue ($object_id, 1, self::RAW);
		commitUpdateAttrValue ($object_id, 21, time() + 10 * 86400);
		$this->assertEscaped ('<td class=tdleft>', '</td>', getOutputOf ('renderExpirations'));
	}
}
