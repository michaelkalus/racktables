<?php

// Make sure renderSearchResults() escapes the search terms and the database
// values it prints.
class RenderSearchResultsTest extends RTTestCase
{
	const TERMS = 'a<b>c</b>"d\'e&f';
	const ESCAPED = 'a&lt;b&gt;c&lt;/b&gt;&quot;d&#039;e&amp;f';

	protected $object_id = NULL;
	protected $attr_ids = array();
	protected $chapter_id = NULL;
	protected $vs_id = NULL;
	protected $ipv4vs_id = NULL;
	protected $row_id = NULL;

	public function tearDown () : void
	{
		if ($this->object_id !== NULL)
			commitDeleteObject ($this->object_id);
		if (count ($this->attr_ids))
		{
			usePreparedDeleteBlade ('AttributeValue', array ('attr_id' => $this->attr_ids));
			usePreparedDeleteBlade ('AttributeMap', array ('attr_id' => $this->attr_ids));
			usePreparedDeleteBlade ('Attribute', array ('id' => $this->attr_ids));
		}
		if ($this->chapter_id !== NULL)
		{
			usePreparedDeleteBlade ('Dictionary', array ('chapter_id' => $this->chapter_id));
			usePreparedDeleteBlade ('Chapter', array ('id' => $this->chapter_id));
		}
		if ($this->vs_id !== NULL)
			usePreparedDeleteBlade ('VS', array ('id' => $this->vs_id));
		if ($this->ipv4vs_id !== NULL)
			usePreparedDeleteBlade ('IPv4VS', array ('id' => $this->ipv4vs_id));
		if ($this->row_id !== NULL)
			commitDeleteObject ($this->row_id);
	}

	// Return a value that includes markup, quotes and an ampersand.
	protected static function probe ($marker)
	{
		return $marker . self::TERMS;
	}

	// Return the same value as it must appear in the output.
	protected static function escaped ($marker)
	{
		return $marker . self::ESCAPED;
	}

	/**
	 * @group small
	 */
	public function testNothingFound ()
	{
		$output = getOutputOf ('renderSearchResults', self::TERMS, array());
		$this->assertStringContainsString ('Nothing found for \'' . self::ESCAPED . '\'', $output);
		$this->assertStringNotContainsString ('<b>', $output);
	}

	/**
	 * @group small
	 */
	public function testSeveralResults ()
	{
		$summary = array
		(
			'ipv4addressbydescr' => array
			(
				array ('ip' => ip4_parse ('192.0.2.1'), 'name' => 'one', 'comment' => ''),
				array ('ip' => ip4_parse ('192.0.2.2'), 'name' => 'two', 'comment' => ''),
			),
		);
		$output = getOutputOf ('renderSearchResults', self::TERMS, $summary);
		$this->assertStringContainsString ('2 result(s) found for \'' . self::ESCAPED . '\'', $output);
		$this->assertStringNotContainsString ('<b>', $output);
	}

	/**
	 * @group small
	 */
	public function testIPAddresses ()
	{
		$summary = array
		(
			'ipv4addressbydescr' => array
			(
				array ('ip' => ip4_parse ('192.0.2.1'), 'name' => self::probe ('name4'), 'comment' => self::probe ('comment4')),
				array ('ip' => ip4_parse ('192.0.2.2'), 'name' => 'two', 'comment' => ''),
			),
			'ipv6addressbydescr' => array
			(
				array ('ip' => ip6_parse ('2001:db8::1'), 'name' => self::probe ('name6'), 'comment' => self::probe ('comment6')),
			),
		);
		$output = getOutputOf ('renderSearchResults', 'x', $summary);
		foreach (array ('name4', 'comment4', 'name6', 'comment6') as $marker)
			$this->assertStringContainsString (self::escaped ($marker), $output);
		$this->assertStringNotContainsString ('<b>', $output);
	}

	/**
	 * @group small
	 */
	public function testObjectReasons ()
	{
		$this->object_id = commitAddObject ($this->myString ('server'), NULL, 4, NULL);
		$port1_id = commitAddPort ($this->object_id, self::probe ('portname'), '1-24', self::probe ('portlabel'), NULL);
		$port2_id = commitAddPort ($this->object_id, 'port 2', '1-24', NULL, NULL);
		$port3_id = commitAddPort ($this->object_id, 'port 3', '1-24', NULL, NULL);
		commitUpdatePortComment ($port2_id, self::probe ('portcomment'));
		linkPorts ($port1_id, $port3_id, self::probe ('cable'));
		bindIPToObject (ip4_parse ('192.0.2.10'), $this->object_id, self::probe ('iface'), 'regular');

		// a string attribute with markup in its name and a wiki-link value
		usePreparedInsertBlade ('Attribute', array ('type' => 'string', 'name' => self::probe ('attrname')));
		$this->attr_ids[] = $str_attr_id = lastInsertID();
		commitSupplementAttrMap ($str_attr_id, 4);
		commitUpdateAttrValue ($this->object_id, $str_attr_id, '[[' . self::probe ('wikitext') . ' | http://example.org/' . self::probe ('wikihref') . ']]');
		// a plain string value
		commitUpdateAttrValue ($this->object_id, 1, self::probe ('sticker'));
		// FQDN, which MGMT_PROTOS turns into a management link for servers
		commitUpdateAttrValue ($this->object_id, 3, self::probe ('fqdn'));
		// a dictionary attribute with a wiki-link value
		usePreparedInsertBlade ('Chapter', array ('name' => $this->myString ('chapter')));
		$this->chapter_id = lastInsertID();
		usePreparedInsertBlade ('Dictionary', array ('chapter_id' => $this->chapter_id, 'dict_value' => '[[' . self::probe ('dicttext') . ' | http://example.org/' . self::probe ('dicthref') . ']]'));
		$dict_key = lastInsertID();
		usePreparedInsertBlade ('Attribute', array ('type' => 'dict', 'name' => $this->myString ('dict')));
		$this->attr_ids[] = $dict_attr_id = lastInsertID();
		commitSupplementAttrMap ($dict_attr_id, 4, $this->chapter_id);
		commitUpdateAttrValue ($this->object_id, $dict_attr_id, $dict_key);

		$summary = array
		(
			'object' => array
			(
				$this->object_id => array
				(
					'id' => $this->object_id,
					'by_port' => array
					(
						$port1_id => self::probe ('portlabel'),
						$port2_id => self::probe ('portcomment'),
					),
					'by_iface' => array (self::probe ('iface')),
					'by_nat' => array (self::probe ('nat')),
					'by_cableid' => array (self::probe ('cable')),
					'by_sticker' => array ($str_attr_id, 1, 3, $dict_attr_id),
				),
			),
			'ipv4addressbydescr' => array
			(
				array ('ip' => ip4_parse ('192.0.2.2'), 'name' => 'two', 'comment' => ''),
			),
		);
		$output = getOutputOf ('renderSearchResults', 'x', $summary);
		$markers = array
		(
			'portname',
			'portlabel',
			'portcomment',
			'iface',
			'nat',
			'cable',
			'attrname',
			'wikitext',
			'wikihref',
			'sticker',
			'fqdn',
			'dicttext',
			'dicthref',
		);
		foreach ($markers as $marker)
			$this->assertStringContainsString (self::escaped ($marker), $output);
		$this->assertStringNotContainsString ('<b>', $output);
		$this->assertStringNotContainsString ('"d\'e', $output);
	}

	/**
	 * @group small
	 */
	public function testVSGroups ()
	{
		usePreparedInsertBlade ('VS', array ('name' => self::probe ('vsgroup')));
		$this->vs_id = lastInsertID();
		$summary = array
		(
			'ipvs' => array ($this->vs_id => spotEntity ('ipvs', $this->vs_id)),
			'ipv4addressbydescr' => array
			(
				array ('ip' => ip4_parse ('192.0.2.2'), 'name' => 'two', 'comment' => ''),
			),
		);
		$output = getOutputOf ('renderSearchResults', 'x', $summary);
		$this->assertStringContainsString (self::escaped ('vsgroup'), $output);
		$this->assertStringNotContainsString ('<b>', $output);
	}

	/**
	 * @group small
	 */
	public function testVirtualServices ()
	{
		usePreparedInsertBlade
		(
			'IPv4VS',
			array
			(
				'vip' => ip4_parse ('192.0.2.20'),
				'vport' => 80,
				'proto' => 'TCP',
				'name' => self::probe ('ipv4vs'),
			)
		);
		$this->ipv4vs_id = lastInsertID();
		$summary = array
		(
			'ipv4vs' => array ($this->ipv4vs_id => spotEntity ('ipv4vs', $this->ipv4vs_id)),
			'ipv4addressbydescr' => array
			(
				array ('ip' => ip4_parse ('192.0.2.2'), 'name' => 'two', 'comment' => ''),
			),
		);
		$output = getOutputOf ('renderSearchResults', 'x', $summary);
		$this->assertStringContainsString (self::escaped ('ipv4vs'), $output);
		$this->assertStringNotContainsString ('<b>', $output);
	}

	/**
	 * @group small
	 */
	public function testRows ()
	{
		$this->row_id = commitAddObject (self::probe ('row'), NULL, 1561, NULL);
		$summary = array
		(
			'row' => array ($this->row_id => spotEntity ('row', $this->row_id)),
			'ipv4addressbydescr' => array
			(
				array ('ip' => ip4_parse ('192.0.2.2'), 'name' => 'two', 'comment' => ''),
			),
		);
		$output = getOutputOf ('renderSearchResults', 'x', $summary);
		$this->assertStringContainsString ('Row ' . self::escaped ('row'), $output);
		$this->assertStringNotContainsString ('<b>', $output);
	}
}
