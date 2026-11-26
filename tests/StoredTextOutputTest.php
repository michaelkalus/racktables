<?php
require_once '../wwwroot/inc/solutions.php';
require_once '../wwwroot/inc/interface-config.php';

// Stored text is printed escaped, whichever form it was stored in: the forms
// of some pages store it as typed and others store it HTML-escaped (issue #73).
class StoredTextOutputTest extends RTTestCase
{
	const MARKER = '<i>x73';
	const ESCAPED_MARKER = '&lt;i&gt;x73';

	protected $location_id, $row_id, $rack_id, $server_id, $escaped_id;
	protected $saved_request, $saved_server, $saved_sic, $saved_auto_tags, $saved_pageno, $saved_tabno;

	public function setUp () : void
	{
		global $sic, $auto_tags, $pageno, $tabno;
		$this->saved_request = $_REQUEST;
		$this->saved_server = $_SERVER;
		$this->saved_sic = $sic;
		$this->saved_auto_tags = $auto_tags;
		$this->saved_pageno = $pageno;
		$this->saved_tabno = $tabno;
		// The default RackCode allows everything to the admin account.
		$auto_tags[] = array ('tag' => '$userid_1');
		@session_start();

		$this->location_id = commitAddObject ($this->text ('location'), NULL, 1562, NULL);
		commitUpdateObject ($this->location_id, $this->text ('location'), NULL, 'no', NULL, $this->text ('location comment'));
		$this->row_id = commitAddObject ($this->text ('row'), NULL, 1561, NULL);
		commitLinkEntities ('location', $this->location_id, 'row', $this->row_id);
		$this->rack_id = commitAddObject ($this->text ('rack'), NULL, 1560, $this->text ('rack asset'));
		commitUpdateObject ($this->rack_id, $this->text ('rack'), NULL, 'no', $this->text ('rack asset'), $this->text ('rack comment'));
		commitUpdateAttrValue ($this->rack_id, 27, 10);
		commitUpdateAttrValue ($this->rack_id, 29, 1);
		commitLinkEntities ('row', $this->row_id, 'rack', $this->rack_id);
		$this->server_id = commitAddObject ($this->text ('server'), $this->text ('server label'), 4, $this->text ('server asset'));
		commitUpdateObject
		(
			$this->server_id,
			$this->text ('server'),
			$this->text ('server label'),
			'no',
			$this->text ('server asset'),
			$this->text ('server comment')
		);
		usePreparedInsertBlade
		(
			'RackSpace',
			array ('rack_id' => $this->rack_id, 'unit_no' => 1, 'atom' => 'front', 'state' => 'T', 'object_id' => $this->server_id)
		);
		// the form that another handler stores
		$this->escaped_id = commitAddObject
		(
			$this->myString ('a73 &amp; b'),
			$this->myString ('l73 &amp; b'),
			4,
			$this->myString ('t73 &amp; b')
		);
		$this->forgetCache();
	}

	public function tearDown () : void
	{
		global $sic, $auto_tags, $pageno, $tabno, $log_messages;
		setMessageBuffering (FALSE);
		$log_messages = array();
		$_REQUEST = $this->saved_request;
		$_SERVER = $this->saved_server;
		$sic = $this->saved_sic;
		$auto_tags = $this->saved_auto_tags;
		$pageno = $this->saved_pageno;
		$tabno = $this->saved_tabno;
		commitDeleteObject ($this->escaped_id);
		commitDeleteObject ($this->server_id);
		usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $this->rack_id));
		commitDeleteObject ($this->rack_id);
		commitDeleteObject ($this->row_id);
		commitDeleteObject ($this->location_id);
		$this->forgetCache();
	}

	// Text as a user types it, with every character that HTML treats specially.
	protected function text ($what)
	{
		return self::MARKER . " & 'q' \"d\" " . $this->myString ($what);
	}

	protected function forgetCache ()
	{
		global $entityCache;
		$entityCache = array();
	}

	protected function render ($pageno_, $tabno_, $args, $func_name, $func_args = array())
	{
		global $pageno, $tabno, $sic;
		$pageno = $pageno_;
		$tabno = $tabno_;
		$_REQUEST = $sic = array_merge (array ('page' => $pageno_, 'tab' => $tabno_), $args);
		$_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query ($_REQUEST);
		return call_user_func_array ('getOutputOf', array_merge (array ($func_name), $func_args));
	}

	protected function assertEscaped ($output, $what)
	{
		$this->assertStringNotContainsString (self::MARKER, $output, $what);
		$this->assertStringContainsString (self::ESCAPED_MARKER, $output, $what);
		// neither form is escaped a second time
		$this->assertStringNotContainsString ('&amp;lt;i', $output, $what);
		$this->assertStringNotContainsString ('&amp;amp;', $output, $what);
	}

	public function providerPages ()
	{
		return array
		(
			'row' => array ('row', 'default', array ('row_id' => 'row'), 'renderRow', array ('row')),
			'rack' => array ('rack', 'default', array ('rack_id' => 'rack'), 'renderRackPage', array ('rack')),
			'rack sort form' => array ('row', 'editracks', array ('row_id' => 'row'), 'renderRackSortForm', array ('row')),
			'object' => array ('object', 'default', array ('object_id' => 'server'), 'renderObject', array ('server')),
			'depot' => array ('depot', 'default', array(), 'renderDepot', array()),
			'location' => array ('location', 'default', array ('location_id' => 'location'), 'renderLocationPage', array ('location')),
			'object log' => array ('objectlog', 'default', array(), 'allObjectLogs', array()),
		);
	}

	public function providerOncePerProcess ()
	{
		return array
		(
			'object rackspace' => array ('object', 'rackspace', array ('object_id' => 'server'), 'renderRackSpaceForObject', array ('server')),
			'rackspace' => array ('rackspace', 'default', array(), 'renderRackspace', array()),
			'location breadcrumbs' => array ('location', 'default', array ('location_id' => 'location'), 'showPathAndSearch', array ('=location', '=default')),
			'row breadcrumbs' => array ('row', 'default', array ('row_id' => 'row'), 'showPathAndSearch', array ('=row', '=default')),
			'rack breadcrumbs' => array ('rack', 'default', array ('rack_id' => 'rack'), 'showPathAndSearch', array ('=rack', '=default')),
			'object breadcrumbs' => array ('object', 'default', array ('object_id' => 'server'), 'showPathAndSearch', array ('=object', '=default')),
		);
	}

	// The provider runs before setUp(), so it names the entities instead of their IDs.
	protected function resolve ($list)
	{
		$ids = array
		(
			'location' => $this->location_id,
			'row' => $this->row_id,
			'rack' => $this->rack_id,
			'server' => $this->server_id,
		);
		$ret = array();
		foreach ($list as $key => $value)
			if (0 === strpos ($value, '='))
				$ret[$key] = substr ($value, 1);
			else
				$ret[$key] = isset ($ids[$value]) ? $ids[$value] : $value;
		return $ret;
	}

	/**
	 * @group small
	 * @dataProvider providerPages
	 */
	public function testPage ($pageno, $tabno, $args, $func_name, $func_args)
	{
		if ($func_name == 'allObjectLogs')
			foreach (array ($this->location_id, $this->row_id, $this->rack_id, $this->server_id) as $id)
				usePreparedInsertBlade ('ObjectLog', array ('object_id' => $id, 'user' => 'admin', 'date' => '2026-01-01 00:00:00', 'content' => 'x73 log'));
		$args = array_map ('strval', $this->resolve ($args));
		$output = $this->render ($pageno, $tabno, $args, $func_name, $this->resolve ($func_args));
		$this->assertEscaped ($output, $func_name);
	}

	/**
	 * showPathAndSearch() and renderRackspace() declare a nested function, so
	 * each can be called only once per process, and getLocationTrail() remembers
	 * the locations of its first call.
	 *
	 * @group small
	 * @dataProvider providerOncePerProcess
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testPageOncePerProcess ($pageno, $tabno, $args, $func_name, $func_args)
	{
		$args = array_map ('strval', $this->resolve ($args));
		$output = $this->render ($pageno, $tabno, $args, $func_name, $this->resolve ($func_args));
		$this->assertEscaped ($output, $func_name);
	}

	/**
	 * @group small
	 */
	public function testTitle ()
	{
		global $pageno, $tabno, $sic;
		foreach (array ('location' => $this->location_id, 'row' => $this->row_id, 'rack' => $this->rack_id) as $realm => $id)
		{
			$pageno = $realm;
			$tabno = 'default';
			$_REQUEST = $sic = array ('page' => $realm, 'tab' => 'default', "{$realm}_id" => (string) $id);
			$this->assertEscaped (getTitle ($realm), "title of {$realm}");
		}
	}

	/**
	 * @group small
	 */
	public function testVirtualService ()
	{
		usePreparedInsertBlade ('IPv4VS', array ('vip' => ip4_parse ('192.0.2.73'), 'vport' => 80, 'proto' => 'TCP', 'name' => $this->text ('vs')));
		$vs_id = lastInsertID();
		try
		{
			$output = $this->render ('ipv4vs', 'default', array ('vs_id' => (string) $vs_id), 'renderVirtualService', array ($vs_id));
			$this->assertEscaped ($output, 'renderVirtualService');
		}
		finally
		{
			usePreparedDeleteBlade ('IPv4VS', array ('id' => $vs_id));
		}
	}

	/**
	 * @group small
	 */
	public function testConfigViewer ()
	{
		$saved = getConfigVar ('enterprise');
		setConfigVar ('enterprise', self::MARKER . ' & co');
		try
		{
			$output = $this->render ('ui', 'default', array(), 'renderUIConfig');
			$this->assertEscaped ($output, 'renderUIConfig');
		}
		finally
		{
			setConfigVar ('enterprise', $saved);
		}
	}

	/**
	 * getLocationTrail() remembers the locations of its first call.
	 *
	 * @group small
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLocationTrail ()
	{
		// HTML with links, and plain text for a caller that escapes it
		$this->assertEscaped (getLocationTrail ($this->location_id), 'linked trail');
		$this->assertSame ($this->text ('location'), getLocationTrail ($this->location_id, FALSE));
	}

	// A value that a form stored HTML-escaped looks the same as before.
	/**
	 * @group small
	 */
	public function testEscapedFormIsNotEscapedAgain ()
	{
		$output = $this->render ('depot', 'default', array(), 'renderDepot');
		foreach (array ('a73', 'l73', 't73') as $prefix)
		{
			$this->assertStringContainsString ($this->myString ("{$prefix} &amp; b"), $output);
			$this->assertStringNotContainsString ("{$prefix} &amp;amp; b", $output);
		}
		$output = $this->render ('object', 'default', array ('object_id' => (string) $this->escaped_id), 'renderObject', array ($this->escaped_id));
		foreach (array ('a73', 'l73', 't73') as $prefix)
		{
			$this->assertStringContainsString ($this->myString ("{$prefix} &amp; b"), $output);
			$this->assertStringNotContainsString ("{$prefix} &amp;amp; b", $output);
		}
	}

	/**
	 * @group small
	 */
	public function testGroupedSelect ()
	{
		$html = getNiftySelect
		(
			array ('group <1> & "x"' => array ("key 'a' <b>" => 'label <i>x73 & y', 2 => 'stored &amp; escaped'), 'second' => array (3 => 'plain')),
			array ('name' => 'test'),
			2
		);
		$this->assertStringNotContainsString ('<i>x73', $html);
		$this->assertStringNotContainsString ('<1>', $html);
		$this->assertStringContainsString ("<optgroup label='group &lt;1&gt; &amp; &quot;x&quot;'>", $html);
		$this->assertStringContainsString ("<option value='key &#039;a&#039; &lt;b&gt;'>label &lt;i&gt;x73 &amp; y</option>", $html);
		$this->assertStringContainsString ("<option value='2' selected>stored &amp; escaped</option>", $html);
	}
}
