<?php
require_once '../wwwroot/inc/dictionary.php';
require_once '../wwwroot/inc/upgrade.php';

// The 0.20.6 upgrade batch converts the old TELNET/SSH/RDP_OBJS_LISTSRC
// config variables into MGMT_PROTOS. The converted value is user-supplied
// RackCode and must survive the round trip through the generated SQL intact.
class UpgradeMgmtProtosTest extends RTTestCase
{
	private static $oldvars = array
	(
		'telnet' => 'TELNET_OBJS_LISTSRC',
		'ssh' => 'SSH_OBJS_LISTSRC',
		'rdp' => 'RDP_OBJS_LISTSRC',
	);
	private $saved_mgmt_protos;

	public function setUp () : void
	{
		$this->saved_mgmt_protos = $this->getMgmtProtos();
		foreach (self::$oldvars as $varname)
			usePreparedInsertBlade
			(
				'Config',
				array
				(
					'varname' => $varname,
					'varvalue' => 'false',
					'vartype' => 'string',
					'emptyok' => 'yes',
					'is_hidden' => 'no',
					'is_userdefined' => 'yes',
				)
			);
	}

	public function tearDown () : void
	{
		foreach (self::$oldvars as $varname)
			usePreparedDeleteBlade ('Config', array ('varname' => $varname));
		usePreparedUpdateBlade ('Config', array ('varvalue' => $this->saved_mgmt_protos), array ('varname' => 'MGMT_PROTOS'));
	}

	private function getMgmtProtos ()
	{
		$result = usePreparedSelectBlade ('SELECT varvalue FROM Config WHERE varname = ?', array ('MGMT_PROTOS'));
		return $result->fetchColumn();
	}

	/**
	 * @group small
	 * @dataProvider providerConvert
	 */
	public function testConvert ($values, $expected)
	{
		foreach ($values as $proto => $varvalue)
			usePreparedUpdateBlade ('Config', array ('varvalue' => $varvalue), array ('varname' => self::$oldvars[$proto]));
		$update = NULL;
		foreach (getUpgradeBatch ('0.20.6') as $q)
			if (strpos ($q, "WHERE varname = 'MGMT_PROTOS'") !== FALSE)
				$update = $q;
		$this->assertNotNull ($update);
		global $dbxlink;
		$dbxlink->exec ($update);
		$this->assertSame ($expected, $this->getMgmtProtos());
	}

	public function providerConvert ()
	{
		return array
		(
			array
			(
				array ('telnet' => '{$typeid_8}', 'ssh' => 'false', 'rdp' => '{$typeid_4}'),
				'telnet: {$typeid_8},rdp: {$typeid_4}',
			),
			array
			(
				array ('telnet' => "{quote'single} or {dq\"x}", 'ssh' => 'false', 'rdp' => 'false'),
				"telnet: {quote'single} or {dq\"x}",
			),
			array
			(
				array ('telnet' => 'false', 'ssh' => "back\\slash \\' \\\\ end\\", 'rdp' => 'false'),
				"ssh: back\\slash \\' \\\\ end\\",
			),
			array
			(
				array ('telnet' => "nul[\x00] nl[\n] cr[\r] sub[\x1a] pct[%_]", 'ssh' => 'false', 'rdp' => 'false'),
				"telnet: nul[\x00] nl[\n] cr[\r] sub[\x1a] pct[%_]",
			),
			array
			(
				array ('telnet' => 'false', 'ssh' => 'false', 'rdp' => "{\xC5\xBDlu\xC5\xA5ou\xC4\x8Dk\xC3\xBD \xE2\x82\xAC \xE6\x97\xA5\xE6\x9C\xAC}"),
				"rdp: {\xC5\xBDlu\xC5\xA5ou\xC4\x8Dk\xC3\xBD \xE2\x82\xAC \xE6\x97\xA5\xE6\x9C\xAC}",
			),
		);
	}
}
