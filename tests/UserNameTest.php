<?php
require_once '../wwwroot/inc/dictionary.php';
require_once '../wwwroot/inc/upgrade.php';
require_once '../wwwroot/inc/interface-config.php';

// User names are used and stored as typed (issue #152): the login name is not
// HTML-escaped any more, the "add user" form stores what it gets, the pages
// escape the names where they print them, and the 0.24.1-community upgrade
// batch decodes once the names that older releases stored escaped.
class UserNameTest extends RTTestCase
{
	const TYPED = "o'b&c <d> \"e\"";

	private $saved_request, $saved_server, $saved_sic, $saved_username, $saved_displayname;
	private $varname;
	private $object_id = NULL;
	private $tag_id = NULL;
	private $vst_id = NULL;
	private $heap_id = NULL;
	private $ip4;
	private $ip6;

	private static function escaped ($text)
	{
		return htmlspecialchars ($text, ENT_QUOTES, 'UTF-8');
	}

	// a name of at most 64 characters in its escaped form
	private function typedName ($what)
	{
		return self::TYPED . $what . getmypid();
	}

	public function setUp () : void
	{
		global $sic, $remote_username, $remote_displayname;
		$this->saved_request = $_REQUEST;
		$this->saved_server = $_SERVER;
		$this->saved_sic = $sic;
		$this->saved_username = $remote_username;
		$this->saved_displayname = isset ($remote_displayname) ? $remote_displayname : NULL;
		$this->varname = substr ('T152_' . getmypid() . '_' . mt_rand(), 0, 32);
		// 198.51.100.152 and 2001:db8::152
		$this->ip4 = ip4_parse ('198.51.100.152');
		$this->ip6 = ip6_parse ('2001:db8::152');
	}

	public function tearDown () : void
	{
		global $sic, $remote_username, $remote_displayname, $log_messages, $entityCache;
		setMessageBuffering (FALSE);
		$log_messages = array();
		$_REQUEST = $this->saved_request;
		$_SERVER = $this->saved_server;
		$sic = $this->saved_sic;
		$remote_username = $this->saved_username;
		$remote_displayname = $this->saved_displayname;
		usePreparedExecuteBlade ('DELETE FROM UserConfig WHERE varname = ?', array ($this->varname));
		usePreparedDeleteBlade ('Config', array ('varname' => $this->varname));
		usePreparedExecuteBlade ('DELETE FROM UserAccount WHERE user_name LIKE ?', array ('%' . getmypid()));
		usePreparedExecuteBlade ('DELETE FROM LDAPCache WHERE presented_username LIKE ?', array ('%' . getmypid()));
		usePreparedDeleteBlade ('IPv4Log', array ('ip' => ip4_bin2db ($this->ip4)));
		usePreparedDeleteBlade ('IPv6Log', array ('ip' => $this->ip6));
		if ($this->object_id !== NULL)
		{
			usePreparedDeleteBlade ('TagStorage', array ('entity_realm' => 'object', 'entity_id' => $this->object_id));
			commitDeleteObject ($this->object_id);
			usePreparedDeleteBlade ('ObjectHistory', array ('id' => $this->object_id));
		}
		if ($this->tag_id !== NULL)
			usePreparedDeleteBlade ('TagTree', array ('id' => $this->tag_id));
		if ($this->vst_id !== NULL)
			usePreparedDeleteBlade ('VLANSwitchTemplate', array ('id' => $this->vst_id));
		if ($this->heap_id !== NULL)
			usePreparedDeleteBlade ('PatchCableHeap', array ('id' => $this->heap_id));
		$entityCache = array();
	}

	private function runBatch ()
	{
		global $dbxlink;
		foreach (getUserNameDecodingQueries() as $query)
			$dbxlink->exec ($query);
	}

	private function column ($sql, $params = array())
	{
		$result = usePreparedSelectBlade ($sql, $params);
		$ret = $result->fetchAll (PDO::FETCH_COLUMN);
		unset ($result);
		return $ret;
	}

	private function setRequest ($args)
	{
		global $sic, $pageno, $tabno, $log_messages, $entityCache;
		$sic = $args;
		$_REQUEST = array();
		foreach ($args as $key => $value)
			$_REQUEST[$key] = is_array ($value) ? $value : htmlspecialchars ($value, ENT_QUOTES, 'UTF-8');
		setMessageBuffering (TRUE);
		$log_messages = array();
		$entityCache = array();
	}

	private function assertMessagesEscaped ()
	{
		global $log_messages;
		$this->assertNotEmpty ($log_messages);
		foreach ($log_messages as $message)
			$this->assertStringNotContainsString ('<d>', var_export ($message, TRUE));
	}

	/**
	 * @group small
	 */
	public function testLoginNameIsLeftAsTyped ()
	{
		$_SERVER['PHP_AUTH_USER'] = self::TYPED;
		$_SERVER['REMOTE_USER'] = self::TYPED;
		$_REQUEST = array ('page' => 'index');
		transformRequestData();
		$this->assertSame (self::TYPED, $_SERVER['PHP_AUTH_USER']);
		$this->assertSame (self::TYPED, $_SERVER['REMOTE_USER']);
	}

	// Every user form stores the name as typed and finds the account by it.
	/**
	 * @group small
	 */
	public function testUserForms ()
	{
		$name = $this->typedName ('form');
		$realname = self::TYPED . ' real';
		$this->setRequest (array ('username' => $name, 'realname' => $realname, 'password' => 'secret 152'));
		createUser();
		$this->assertMessagesEscaped();
		$user_id = getUserIDByUsername ($name);
		$this->assertNotNull ($user_id, 'an account named as typed');
		$this->assertNull (getUserIDByUsername (self::escaped ($name)));
		$this->assertSame (array ($realname), $this->column ('SELECT user_realname FROM UserAccount WHERE user_id = ?', array ($user_id)));
		// what the login does with the name as the browser sends it
		$userinfo = constructUserCell ($name);
		$this->assertEquals ($user_id, $userinfo['user_id']);
		$this->assertTrue (authenticated_via_database ($userinfo, 'secret 152'));

		$this->setRequest (array ('user_id' => (string) $user_id, 'username' => $name . 'x', 'realname' => $realname, 'password' => ''));
		updateUser();
		$this->assertMessagesEscaped();
		$this->assertEquals ($user_id, getUserIDByUsername ($name . 'x'));

		$this->setRequest (array ('id' => (string) $user_id));
		deleteUser();
		$this->assertMessagesEscaped();
		$this->assertNull (getUserIDByUsername ($name . 'x'));
	}

	/**
	 * @group small
	 */
	public function testNamesAreEscapedInOutput ()
	{
		global $remote_username, $remote_displayname;
		$remote_username = self::TYPED;
		$remote_displayname = self::TYPED . ' real';
		$output = getOutputOf ('renderMyAccount');
		$this->assertStringNotContainsString ('<d>', $output);
		$this->assertStringContainsString ('<td>' . self::escaped (self::TYPED) . '</td>', $output);
		$this->assertStringContainsString ('<td>' . self::escaped (self::TYPED . ' real') . '</td>', $output);
		// the automatic tag of a user carries the name
		$output = serializeTags (array (array ('tag' => '$username_' . self::TYPED)));
		$this->assertStringNotContainsString ('<d>', $output);
		$this->assertStringContainsString ('$username_' . self::escaped (self::TYPED), $output);
	}

	// Rows as the releases before 0.24.1-community wrote them for a login
	// name with special characters.
	private function seed ($typed)
	{
		global $dbxlink;
		$old = self::escaped ($typed);
		$user_id = commitCreateUserAccount ($old, self::escaped (self::TYPED . ' real'), makePasswordHash ('x'));
		usePreparedInsertBlade ('Config', array ('varname' => $this->varname, 'varvalue' => '', 'vartype' => 'string', 'emptyok' => 'yes', 'is_hidden' => 'no', 'is_userdefined' => 'yes', 'description' => 'test'));
		usePreparedInsertBlade ('UserConfig', array ('varname' => $this->varname, 'varvalue' => 'mine', 'user' => $old));
		$this->object_id = commitAddObject ($this->myString ('server'), NULL, 4, NULL);
		usePreparedInsertBlade ('ObjectLog', array ('object_id' => $this->object_id, 'user' => $old, 'date' => '2026-01-01 00:00:00', 'content' => 'kept &amp; as typed'));
		usePreparedExecuteBlade
		(
			"INSERT INTO ObjectHistory (id, name, objtype_id, ctime, user_name) VALUES (?, ?, 4, '2018-01-30 09:40:23', ?)",
			array ($this->object_id, 'history 152', $old)
		);
		usePreparedInsertBlade ('MountOperation', array ('object_id' => $this->object_id, 'ctime' => '2018-01-30 09:40:23', 'user_name' => $old, 'comment' => 'c'));
		$port_id = commitAddPort ($this->object_id, 'p152', '1-24', '', '');
		usePreparedInsertBlade ('PortLog', array ('port_id' => $port_id, 'date' => '2026-01-01 00:00:00', 'user' => $old, 'message' => "linked to R&amp;D &lt;sw&gt; ge-0/0/1"));
		usePreparedInsertBlade ('IPv4Log', array ('ip' => ip4_bin2db ($this->ip4), 'date' => '2026-01-01 00:00:00', 'user' => $old, 'message' => 'Binded with R&amp;D, ifname=eth0'));
		usePreparedInsertBlade ('IPv6Log', array ('ip' => $this->ip6, 'date' => '2026-01-01 00:00:00', 'user' => $old, 'message' => 'Removed from &quot;x&quot;'));
		usePreparedInsertBlade ('TagTree', array ('tag' => $this->myString ('tag')));
		$this->tag_id = lastInsertID();
		usePreparedInsertBlade ('TagStorage', array ('entity_realm' => 'object', 'entity_id' => $this->object_id, 'tag_id' => $this->tag_id, 'user' => $old, 'date' => '2026-01-01 00:00:00'));
		usePreparedInsertBlade ('VLANSwitchTemplate', array ('mutex_rev' => 1, 'description' => $this->myString ('vst'), 'saved_by' => $old));
		$this->vst_id = lastInsertID();
		$result = $dbxlink->query ('SELECT pctype_id, connector_id FROM PatchCableConnectorCompat LIMIT 1');
		$compat = $result->fetch (PDO::FETCH_NUM);
		unset ($result);
		usePreparedInsertBlade ('PatchCableHeap', array ('pctype_id' => $compat[0], 'end1_conn_id' => $compat[1], 'end2_conn_id' => $compat[1]));
		$this->heap_id = lastInsertID();
		usePreparedInsertBlade ('PatchCableHeapLog', array ('heap_id' => $this->heap_id, 'date' => '2026-01-01 00:00:00', 'user' => $old, 'message' => 'amount set to 1'));
		usePreparedInsertBlade ('LDAPCache', array ('presented_username' => $old, 'successful_hash' => 'x'));
		return $user_id;
	}

	/**
	 * @group small
	 */
	public function testDecoding ()
	{
		$typed = $this->typedName ('dec');
		$user_id = $this->seed ($typed);
		$this->runBatch();
		$check = function ()  use ($typed, $user_id)
		{
			$this->assertSame (array ($typed), $this->column ('SELECT user_name FROM UserAccount WHERE user_id = ?', array ($user_id)));
			$this->assertSame (array (self::TYPED . ' real'), $this->column ('SELECT user_realname FROM UserAccount WHERE user_id = ?', array ($user_id)));
			$this->assertSame (array ($typed), $this->column ('SELECT user FROM UserConfig WHERE varname = ?', array ($this->varname)));
			$this->assertSame (array ($typed), $this->column ('SELECT user FROM ObjectLog WHERE object_id = ?', array ($this->object_id)));
			$this->assertSame (array ('kept &amp; as typed'), $this->column ('SELECT content FROM ObjectLog WHERE object_id = ?', array ($this->object_id)));
			$this->assertSame (array ($typed), $this->column ('SELECT user_name FROM ObjectHistory WHERE id = ? AND name = ?', array ($this->object_id, 'history 152')));
			$this->assertSame (array ($typed), $this->column ('SELECT user_name FROM MountOperation WHERE object_id = ?', array ($this->object_id)));
			// the records keep the time of the change
			$this->assertSame (array ('2018-01-30 09:40:23'), $this->column ('SELECT ctime FROM ObjectHistory WHERE id = ? AND name = ?', array ($this->object_id, 'history 152')));
			$this->assertSame (array ('2018-01-30 09:40:23'), $this->column ('SELECT ctime FROM MountOperation WHERE object_id = ?', array ($this->object_id)));
			$this->assertSame (array ($typed), $this->column ('SELECT PortLog.user FROM PortLog INNER JOIN Port ON port_id = Port.id WHERE object_id = ?', array ($this->object_id)));
			$this->assertSame (array ('linked to R&D <sw> ge-0/0/1'), $this->column ('SELECT message FROM PortLog INNER JOIN Port ON port_id = Port.id WHERE object_id = ?', array ($this->object_id)));
			$this->assertSame (array ($typed), $this->column ('SELECT user FROM IPv4Log WHERE ip = ?', array (ip4_bin2db ($this->ip4))));
			$this->assertSame (array ('Binded with R&D, ifname=eth0'), $this->column ('SELECT message FROM IPv4Log WHERE ip = ?', array (ip4_bin2db ($this->ip4))));
			$this->assertSame (array ($typed), $this->column ('SELECT user FROM IPv6Log WHERE ip = ?', array ($this->ip6)));
			$this->assertSame (array ('Removed from "x"'), $this->column ('SELECT message FROM IPv6Log WHERE ip = ?', array ($this->ip6)));
			$this->assertSame (array ($typed), $this->column ('SELECT user FROM TagStorage WHERE entity_realm = ? AND entity_id = ?', array ('object', $this->object_id)));
			$this->assertSame (array ($typed), $this->column ('SELECT saved_by FROM VLANSwitchTemplate WHERE id = ?', array ($this->vst_id)));
			$this->assertSame (array ($typed), $this->column ('SELECT user FROM PatchCableHeapLog WHERE heap_id = ?', array ($this->heap_id)));
			$this->assertSame (array(), $this->column ('SELECT presented_username FROM LDAPCache'));
		};
		$check();
		// the account is found by the name the browser sends
		$this->assertEquals ($user_id, getUserIDByUsername ($typed));
		// a second run changes nothing
		$this->runBatch();
		$check();
	}

	// An account whose decoded name belongs to another account keeps its name,
	// its preferences and its change records.
	/**
	 * @group small
	 */
	public function testNameTakenByAnotherAccount ()
	{
		$typed = $this->typedName ('dup');
		$old = self::escaped ($typed);
		$escaped_id = commitCreateUserAccount ($old, 'escaped', makePasswordHash ('x'));
		$typed_id = commitCreateUserAccount ($typed, 'typed', makePasswordHash ('x'));
		usePreparedInsertBlade ('Config', array ('varname' => $this->varname, 'varvalue' => '', 'vartype' => 'string', 'emptyok' => 'yes', 'is_hidden' => 'no', 'is_userdefined' => 'yes', 'description' => 'test'));
		usePreparedInsertBlade ('UserConfig', array ('varname' => $this->varname, 'varvalue' => 'of the escaped name', 'user' => $old));
		usePreparedInsertBlade ('IPv4Log', array ('ip' => ip4_bin2db ($this->ip4), 'date' => '2026-01-01 00:00:00', 'user' => $old, 'message' => 'm'));
		$this->object_id = commitAddObject ($this->myString ('server'), NULL, 4, NULL);
		usePreparedInsertBlade ('ObjectLog', array ('object_id' => $this->object_id, 'user' => $old, 'date' => '2026-01-01 00:00:00', 'content' => 'c'));
		$this->runBatch();
		$this->assertSame (array ($old), $this->column ('SELECT user FROM IPv4Log WHERE ip = ?', array (ip4_bin2db ($this->ip4))));
		$this->assertSame (array ($old), $this->column ('SELECT user FROM ObjectLog WHERE object_id = ?', array ($this->object_id)));
		$this->assertSame (array ($old), $this->column ('SELECT user_name FROM UserAccount WHERE user_id = ?', array ($escaped_id)));
		$this->assertSame (array ($typed), $this->column ('SELECT user_name FROM UserAccount WHERE user_id = ?', array ($typed_id)));
		$this->assertSame (array ($old), $this->column ('SELECT user FROM UserConfig WHERE varname = ?', array ($this->varname)));
	}

	// Without an account (LDAP, httpd) the preferences follow the name, unless
	// the decoded name has a value of the same option already.
	/**
	 * @group small
	 */
	public function testPreferencesWithoutAccount ()
	{
		$typed = $this->typedName ('ext');
		$old = self::escaped ($typed);
		usePreparedInsertBlade ('Config', array ('varname' => $this->varname, 'varvalue' => '', 'vartype' => 'string', 'emptyok' => 'yes', 'is_hidden' => 'no', 'is_userdefined' => 'yes', 'description' => 'test'));
		usePreparedInsertBlade ('UserConfig', array ('varname' => $this->varname, 'varvalue' => 'old', 'user' => $old));
		$this->runBatch();
		$this->assertSame (array ($typed), $this->column ('SELECT user FROM UserConfig WHERE varname = ?', array ($this->varname)));
		usePreparedInsertBlade ('UserConfig', array ('varname' => $this->varname, 'varvalue' => 'older', 'user' => $old));
		$this->runBatch();
		$this->assertSame
		(
			array ('old', 'older'),
			$this->column ('SELECT varvalue FROM UserConfig WHERE varname = ? ORDER BY varvalue', array ($this->varname))
		);
	}

	// SAML authentication recorded the name as it is, entity-like or not.
	/**
	 * @group small
	 */
	public function testSAMLNamesAreKept ()
	{
		global $user_auth_src;
		$name = 'alice&amp;ops' . getmypid();
		usePreparedInsertBlade ('IPv4Log', array ('ip' => ip4_bin2db ($this->ip4), 'date' => '2026-01-01 00:00:00', 'user' => $name, 'message' => 'm'));
		$saved = $user_auth_src;
		$user_auth_src = 'saml';
		try
		{
			$this->runBatch();
		}
		finally
		{
			$user_auth_src = $saved;
		}
		$this->assertSame (array ($name), $this->column ('SELECT user FROM IPv4Log WHERE ip = ?', array (ip4_bin2db ($this->ip4))));
	}

	// Names without the five characters are not touched.
	/**
	 * @group small
	 */
	public function testPlainNames ()
	{
		$before = $this->column ('SELECT user_name FROM UserAccount ORDER BY user_id');
		$this->assertContains ('admin', $before);
		$this->runBatch();
		$this->assertSame ($before, $this->column ('SELECT user_name FROM UserAccount ORDER BY user_id'));
	}
}
