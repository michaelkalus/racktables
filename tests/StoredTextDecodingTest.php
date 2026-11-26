<?php
require_once '../wwwroot/inc/dictionary.php';
require_once '../wwwroot/inc/upgrade.php';

// The 0.24.0-community upgrade batch decodes, once, the text that older
// releases stored HTML-escaped (issue #73). This test runs the queries of
// getStoredTextDecodingQueries() on rows written the old way.
class StoredTextDecodingTest extends RTTestCase
{
	const TYPED = "a&b <c> \"d\" 'e'";

	private $object_ids = array();
	private $varname;
	private $username;
	private $pool_id = NULL;
	private $vs_id = NULL;
	private $rackcode;
	private $ip4;
	private $ip6;

	private static function escaped ($text)
	{
		return htmlspecialchars ($text, ENT_QUOTES, 'UTF-8');
	}

	public function setUp () : void
	{
		$this->varname = substr ('T73_' . getmypid() . '_' . mt_rand(), 0, 32);
		$this->username = $this->myString ('user');
		$this->rackcode = loadScript ('RackCode');
		// 198.51.100.73 and 2001:db8::73
		$this->ip4 = ip4_parse ('198.51.100.73');
		$this->ip6 = ip6_parse ('2001:db8::73');
	}

	public function tearDown () : void
	{
		usePreparedDeleteBlade ('UserConfig', array ('varname' => $this->varname));
		usePreparedDeleteBlade ('Config', array ('varname' => $this->varname));
		usePreparedDeleteBlade ('IPv4Address', array ('ip' => ip4_bin2db ($this->ip4)));
		usePreparedDeleteBlade ('IPv6Address', array ('ip' => $this->ip6));
		foreach ($this->object_ids as $object_id)
		{
			usePreparedDeleteBlade ('IPv4LB', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('IPv4NAT', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('IPv4Allocation', array ('object_id' => $object_id));
			usePreparedDeleteBlade ('IPv6Allocation', array ('object_id' => $object_id));
			commitDeleteObject ($object_id);
			usePreparedDeleteBlade ('ObjectHistory', array ('id' => $object_id));
		}
		if ($this->vs_id !== NULL)
			usePreparedDeleteBlade ('IPv4VS', array ('id' => $this->vs_id));
		if ($this->pool_id !== NULL)
			usePreparedDeleteBlade ('IPv4RSPool', array ('id' => $this->pool_id));
		saveScript ('RackCode', $this->rackcode);
	}

	private function addObject ($name, $label, $asset_no)
	{
		$object_id = commitAddObject ($name, $label, 4, $asset_no);
		$this->object_ids[] = $object_id;
		return $object_id;
	}

	private function runQueries ()
	{
		global $dbxlink;
		foreach (getStoredTextDecodingQueries() as $query)
			$dbxlink->exec ($query);
	}

	private function fetchRow ($sql, $params)
	{
		$result = usePreparedSelectBlade ($sql, $params);
		$row = $result->fetch (PDO::FETCH_ASSOC);
		$result->closeCursor();
		return $row;
	}

	/**
	 * @group small
	 */
	public function testExpression ()
	{
		foreach (array
		(
			self::escaped (self::TYPED) => self::TYPED,
			// one level only
			self::escaped (self::escaped (self::TYPED)) => self::escaped (self::TYPED),
			'&amp;lt;' => '&lt;',
			'&amp;amp;' => '&amp;',
			// not what htmlspecialchars() writes
			'&AMP; &#39; &nbsp; &copy; &' => '&AMP; &#39; &nbsp; &copy; &',
			'no entity; here & there' => 'no entity; here & there',
			'' => '',
		) as $stored => $expected)
		{
			$row = $this->fetchRow ('SELECT ' . getHTMLDecodingSQL ('?') . ' AS decoded', array ($stored));
			$this->assertSame ($expected, $row['decoded'], $stored);
		}
	}

	/**
	 * @group small
	 */
	public function testColumns ()
	{
		$e = self::escaped (self::TYPED);
		$name = $this->myString ('n') . self::TYPED;
		$asset = $this->myString ('a') . '&<>';

		// an object, its history, ports, a link, allocations and a NAT rule
		$object_id = $this->addObject (self::escaped ($name), $e, self::escaped ($asset));
		commitUpdateObject ($object_id, self::escaped ($name), $e, 'no', self::escaped ($asset), "line 1\n" . $e);
		$porta = commitAddPort ($object_id, $e, '1-24', $e, '');
		$portb = commitAddPort ($object_id, 'plain', '1-24', NULL, '');
		linkPorts ($porta, $portb, $e);
		usePreparedInsertBlade ('IPv4Allocation', array ('object_id' => $object_id, 'ip' => ip4_bin2db ($this->ip4), 'name' => $e));
		usePreparedInsertBlade ('IPv6Allocation', array ('object_id' => $object_id, 'ip' => $this->ip6, 'name' => $e));
		usePreparedInsertBlade ('IPv4NAT', array ('object_id' => $object_id, 'proto' => 'TCP', 'localip' => ip4_bin2db ($this->ip4), 'localport' => 80, 'remoteip' => ip4_bin2db ($this->ip4), 'remoteport' => 8080, 'description' => $e));
		usePreparedInsertBlade ('IPv4Address', array ('ip' => ip4_bin2db ($this->ip4), 'name' => $e, 'comment' => $e));
		usePreparedInsertBlade ('IPv6Address', array ('ip' => $this->ip6, 'name' => $e, 'comment' => $e));
		// SLB
		usePreparedInsertBlade ('IPv4VS', array ('vip' => $this->ip4, 'vport' => 80, 'proto' => 'TCP', 'name' => $e));
		$this->vs_id = lastInsertID();
		usePreparedInsertBlade ('IPv4RSPool', array ('name' => $e));
		$this->pool_id = lastInsertID();
		usePreparedInsertBlade ('IPv4LB', array ('object_id' => $object_id, 'rspool_id' => $this->pool_id, 'vs_id' => $this->vs_id, 'prio' => $e));
		// values that were never escaped stay as they are
		$raw_id = $this->addObject ($this->myString ('raw') . ' R&D; <b>', 'x & y; z', NULL);

		$this->runQueries();

		$row = $this->fetchRow ('SELECT name, label, asset_no, comment FROM Object WHERE id = ?', array ($object_id));
		$this->assertSame (array ('name' => $name, 'label' => self::TYPED, 'asset_no' => $asset, 'comment' => "line 1\n" . self::TYPED), $row);
		$result = usePreparedSelectBlade ('SELECT name, label, asset_no, comment FROM ObjectHistory WHERE id = ?', array ($object_id));
		$history = $result->fetchAll (PDO::FETCH_ASSOC);
		$result->closeCursor();
		$this->assertNotEmpty ($history);
		foreach ($history as $h)
		{
			$this->assertSame ($name, $h['name']);
			$this->assertSame (self::TYPED, $h['label']);
			$this->assertSame ($asset, $h['asset_no']);
		}
		$row = $this->fetchRow ('SELECT name, label FROM Port WHERE id = ?', array ($porta));
		$this->assertSame (array ('name' => self::TYPED, 'label' => self::TYPED), $row);
		$row = $this->fetchRow ('SELECT cable FROM Link WHERE porta IN (?, ?)', array ($porta, $portb));
		$this->assertSame (self::TYPED, $row['cable']);
		foreach (array
		(
			'SELECT name FROM IPv4Allocation WHERE object_id = ?' => $object_id,
			'SELECT name FROM IPv6Allocation WHERE object_id = ?' => $object_id,
			'SELECT description FROM IPv4NAT WHERE object_id = ?' => $object_id,
			'SELECT prio FROM IPv4LB WHERE object_id = ?' => $object_id,
			'SELECT name FROM IPv4VS WHERE id = ?' => $this->vs_id,
			'SELECT name FROM IPv4RSPool WHERE id = ?' => $this->pool_id,
		) as $sql => $id)
		{
			$row = $this->fetchRow ($sql, array ($id));
			$this->assertSame (self::TYPED, reset ($row), $sql);
		}
		$row = $this->fetchRow ('SELECT name, comment FROM IPv4Address WHERE ip = ?', array (ip4_bin2db ($this->ip4)));
		$this->assertSame (array ('name' => self::TYPED, 'comment' => self::TYPED), $row);
		$row = $this->fetchRow ('SELECT name, comment FROM IPv6Address WHERE ip = ?', array ($this->ip6));
		$this->assertSame (array ('name' => self::TYPED, 'comment' => self::TYPED), $row);
		$row = $this->fetchRow ('SELECT name, label FROM Object WHERE id = ?', array ($raw_id));
		$this->assertSame (array ('name' => $this->myString ('raw') . ' R&D; <b>', 'label' => 'x & y; z'), $row);

		// a second run changes nothing
		$this->runQueries();
		$row = $this->fetchRow ('SELECT name, label, asset_no, comment FROM Object WHERE id = ?', array ($object_id));
		$this->assertSame (array ('name' => $name, 'label' => self::TYPED, 'asset_no' => $asset, 'comment' => "line 1\n" . self::TYPED), $row);
	}

	// A decoded value that would be a duplicate on a unique key stays escaped,
	// and the other columns of the row are decoded all the same.
	/**
	 * @group small
	 */
	public function testUniqueKeyCollision ()
	{
		$asset = $this->myString ('a') . '&x';
		$typed_id = $this->addObject ($this->myString ('typed'), NULL, $asset);
		$escaped_id = $this->addObject ($this->myString ('escaped'), self::escaped (self::TYPED), self::escaped ($asset));
		$port_typed = commitAddPort ($typed_id, 'p&1', '1-24', NULL, '');
		$port_escaped = commitAddPort ($typed_id, 'p&amp;1', '1-24', self::escaped (self::TYPED), '');

		$this->runQueries();

		$row = $this->fetchRow ('SELECT asset_no FROM Object WHERE id = ?', array ($typed_id));
		$this->assertSame ($asset, $row['asset_no']);
		$row = $this->fetchRow ('SELECT label, asset_no FROM Object WHERE id = ?', array ($escaped_id));
		$this->assertSame (array ('label' => self::TYPED, 'asset_no' => self::escaped ($asset)), $row);
		$row = $this->fetchRow ('SELECT name FROM Port WHERE id = ?', array ($port_typed));
		$this->assertSame ('p&1', $row['name']);
		$row = $this->fetchRow ('SELECT name, label FROM Port WHERE id = ?', array ($port_escaped));
		$this->assertSame (array ('name' => 'p&amp;1', 'label' => self::TYPED), $row);
	}

	private function add8021QRows ($object_id, $port_name, $native_vlan)
	{
		usePreparedInsertBlade ('CachedPVM', array ('object_id' => $object_id, 'port_name' => $port_name, 'vlan_mode' => 'trunk'));
		usePreparedInsertBlade ('PortVLANMode', array ('object_id' => $object_id, 'port_name' => $port_name, 'vlan_mode' => 'trunk'));
		foreach (array ('Cached' => array ('PAV', 'PNV'), 'Port' => array ('AllowedVLAN', 'NativeVLAN')) as $prefix => $suffixes)
		{
			foreach (array ($native_vlan, $native_vlan + 1) as $vlan_id)
				usePreparedInsertBlade ($prefix . $suffixes[0], array ('object_id' => $object_id, 'port_name' => $port_name, 'vlan_id' => $vlan_id));
			usePreparedInsertBlade ($prefix . $suffixes[1], array ('object_id' => $object_id, 'port_name' => $port_name, 'vlan_id' => $native_vlan));
		}
	}

	// Returns table => list of "port_name/vlan" for the object.
	private function get8021QRows ($object_id)
	{
		$ret = array();
		foreach (array ('CachedPVM' => 'vlan_mode', 'CachedPAV' => 'vlan_id', 'CachedPNV' => 'vlan_id', 'PortVLANMode' => 'vlan_mode', 'PortAllowedVLAN' => 'vlan_id', 'PortNativeVLAN' => 'vlan_id') as $table => $column)
		{
			$result = usePreparedSelectBlade ("SELECT CONCAT(port_name, '/', {$column}) FROM {$table} WHERE object_id = ? ORDER BY port_name, {$column}", array ($object_id));
			$ret[$table] = $result->fetchAll (PDO::FETCH_COLUMN, 0);
			$result->closeCursor();
		}
		return $ret;
	}

	// The 802.1Q rows of a port follow its decoded name. A name whose decoded
	// form already has rows for the same object is left alone with all its rows.
	/**
	 * @group small
	 */
	public function test8021QPortNames ()
	{
		$object_id = $this->addObject ($this->myString ('switch'), NULL, NULL);
		$this->add8021QRows ($object_id, 'eth&amp;1', 10);
		$this->add8021QRows ($object_id, 'eth2', 20);
		// both forms of one name
		$this->add8021QRows ($object_id, 'po&lt;3&gt;', 30);
		$this->add8021QRows ($object_id, 'po<3>', 40);
		// a name whose decoded form is taken at the time waits for another run
		$this->add8021QRows ($object_id, 'x&amp;amp;', 50);
		$this->add8021QRows ($object_id, 'x&amp;', 60);
		$other_id = $this->addObject ($this->myString ('switch2'), NULL, NULL);
		$this->add8021QRows ($other_id, 'po&lt;3&gt;', 30);
		// A port that keeps its escaped name, because the decoded one belongs to
		// another port of the same type, keeps its rows. With another type the
		// port is renamed and the rows follow.
		$kept_id = $this->addObject ($this->myString ('switch3'), NULL, NULL);
		// (a server also gets ports of its own from AUTOPORTS_CONFIG)
		commitAddPort ($kept_id, 'p&1', '1-24', NULL, '');
		commitAddPort ($kept_id, 'p&amp;1', '1-24', NULL, '');
		commitAddPort ($kept_id, 'q&1', '1-24', NULL, '');
		commitAddPort ($kept_id, 'q&amp;1', '1-19', NULL, '');
		$this->add8021QRows ($kept_id, 'p&amp;1', 70);
		$this->add8021QRows ($kept_id, 'q&amp;1', 80);

		$this->runQueries();

		$mode = array ('eth&1/trunk', 'eth2/trunk', 'po&lt;3&gt;/trunk', 'po<3>/trunk', 'x&/trunk', 'x&amp;amp;/trunk');
		$allowed = array ('eth&1/10', 'eth&1/11', 'eth2/20', 'eth2/21', 'po&lt;3&gt;/30', 'po&lt;3&gt;/31', 'po<3>/40', 'po<3>/41', 'x&/60', 'x&/61', 'x&amp;amp;/50', 'x&amp;amp;/51');
		$native = array ('eth&1/10', 'eth2/20', 'po&lt;3&gt;/30', 'po<3>/40', 'x&/60', 'x&amp;amp;/50');
		$this->assertSame
		(
			array ('CachedPVM' => $mode, 'CachedPAV' => $allowed, 'CachedPNV' => $native, 'PortVLANMode' => $mode, 'PortAllowedVLAN' => $allowed, 'PortNativeVLAN' => $native),
			$this->get8021QRows ($object_id)
		);
		$rows = $this->get8021QRows ($other_id);
		$this->assertSame (array ('po<3>/trunk'), $rows['CachedPVM']);
		$this->assertSame (array ('po<3>/30', 'po<3>/31'), $rows['PortAllowedVLAN']);
		$this->assertSame (array ('po<3>/30'), $rows['PortNativeVLAN']);

		$rows = $this->get8021QRows ($kept_id);
		$this->assertSame (array ('p&amp;1/trunk', 'q&1/trunk'), $rows['CachedPVM']);
		$this->assertSame (array ('p&amp;1/70', 'q&1/80'), $rows['PortNativeVLAN']);
		$result = usePreparedSelectBlade ("SELECT name FROM Port WHERE object_id = ? AND (name LIKE 'p%' OR name LIKE 'q%') ORDER BY name, type", array ($kept_id));
		$this->assertSame (array ('p&1', 'p&amp;1', 'q&1', 'q&1'), $result->fetchAll (PDO::FETCH_COLUMN, 0));
		$result->closeCursor();

		// a second run has nothing to do but the name that was waiting
		$this->assertSame (array(), array_filter (get8021QPortNameDecodingQueries(), function ($q) { return FALSE === strpos ($q, 'x&amp;amp;'); }));
	}

	/**
	 * @group small
	 */
	public function testRackCodeCacheIsDropped ()
	{
		$cache = loadScript ('RackCodeCache');
		try
		{
			saveScript ('RackCodeCache', 'stale');
			$this->runQueries();
			$this->assertNull (loadScript ('RackCodeCache'));
		}
		finally
		{
			saveScript ('RackCodeCache', $cache);
		}
	}

	/**
	 * @group small
	 */
	public function testDecodeOnceMatchesSQL ()
	{
		foreach (array ('&amp;lt;', '&amp;amp;amp;', '&lt;&gt;&quot;&#039;&amp;', 'a&b;c', '&#39;&AMP;', '') as $text)
		{
			$row = $this->fetchRow ('SELECT ' . getHTMLDecodingSQL ('?') . ' AS decoded', array ($text));
			$this->assertSame ($row['decoded'], decodeHTMLOnce ($text), $text);
		}
	}

	// The configuration forms added a level of escaping on every save.
	/**
	 * @group small
	 */
	public function testConfigValues ()
	{
		$three_levels = self::escaped (self::escaped (self::escaped (self::TYPED)));
		usePreparedInsertBlade ('Config', array ('varname' => $this->varname, 'varvalue' => $three_levels, 'vartype' => 'string', 'emptyok' => 'yes', 'is_hidden' => 'yes', 'is_userdefined' => 'yes', 'description' => '&quot;quoted&quot; &lt;description&gt;'));
		usePreparedInsertBlade ('UserConfig', array ('varname' => $this->varname, 'varvalue' => self::escaped (self::TYPED), 'user' => $this->username));
		usePreparedInsertBlade ('UserConfig', array ('varname' => $this->varname, 'varvalue' => 'page1,page2', 'user' => $this->username . '2'));

		$this->runQueries();

		$row = $this->fetchRow ('SELECT varvalue, description FROM Config WHERE varname = ?', array ($this->varname));
		$this->assertSame (self::TYPED, $row['varvalue']);
		// descriptions hold entities on purpose
		$this->assertSame ('&quot;quoted&quot; &lt;description&gt;', $row['description']);
		$row = $this->fetchRow ('SELECT varvalue FROM UserConfig WHERE varname = ? AND user = ?', array ($this->varname, $this->username));
		$this->assertSame (self::TYPED, $row['varvalue']);
		$row = $this->fetchRow ('SELECT varvalue FROM UserConfig WHERE varname = ? AND user = ?', array ($this->varname, $this->username . '2'));
		$this->assertSame ('page1,page2', $row['varvalue']);
		// nothing left to do for these tables
		foreach (getStoredTextDecodingQueries() as $query)
			$this->assertSame (0, preg_match ('/^UPDATE (User)?Config /', $query), $query);
	}

	// Escaped and typed RackCode differ only in comments.
	/**
	 * @group small
	 */
	public function testRackCode ()
	{
		$comment = "# " . self::TYPED . "\n";
		saveScript ('RackCode', self::escaped ($comment) . "allow {\$userid_1}\n");
		$this->runQueries();
		$this->assertSame ($comment . "allow {\$userid_1}\n", loadScript ('RackCode'));
		$tree = getRackCode (loadScript ('RackCode'));
		$this->assertSame ('ACK', $tree['result']);
	}
}
