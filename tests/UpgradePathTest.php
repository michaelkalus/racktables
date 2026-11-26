<?php
require_once '../wwwroot/inc/dictionary.php';
require_once '../wwwroot/inc/upgrade.php';

// The upgrader finds its batches by the position of the database version in the
// version list of getDBUpgradePath(). The community fork appends its own versions
// (with a "-community" suffix) after the upstream 0.22.x ones.
class UpgradePathTest extends RTTestCase
{
	public function providerPaths ()
	{
		return array
		(
			'upstream 0.22.0' => array ('0.22.0', array ('0.22.1', '0.23.0-community', '0.23.1-community')),
			'upstream 0.22.1' => array ('0.22.1', array ('0.23.0-community', '0.23.1-community')),
			'0.23.0-community' => array ('0.23.0-community', array ('0.23.1-community')),
			'0.23.1-community itself' => array ('0.23.1-community', array()),
			'unknown 0.22.2' => array ('0.22.2', NULL),
			'unknown plain 0.23.0' => array ('0.23.0', NULL),
			'unknown 0.19.0' => array ('0.19.0', NULL),
			'other spelling' => array ('0.23.0-COMMUNITY', NULL),
			'empty' => array ('', NULL),
		);
	}

	/**
	 * @group small
	 * @dataProvider providerPaths
	 */
	public function testPathToCodeVersion ($dbver, $expected)
	{
		$this->assertSame ($expected, getDBUpgradePath ($dbver, CODE_VERSION));
	}

	/**
	 * @group small
	 */
	public function testCodeVersionIsLastInList ()
	{
		$path = getDBUpgradePath ('0.20.0', CODE_VERSION);
		$this->assertIsArray ($path);
		$this->assertSame ('0.20.1', reset ($path));
		$this->assertSame (CODE_VERSION, end ($path));
		$this->assertSame (array_values (array_unique ($path)), $path);
		// nothing comes after the current version
		$this->assertSame (array(), getDBUpgradePath (CODE_VERSION, CODE_VERSION));
	}

	/**
	 * @group small
	 */
	public function testBatchOfCodeVersionSetsDBVersion ()
	{
		$batch = getUpgradeBatch (CODE_VERSION);
		$this->assertIsArray ($batch);
		$this->assertSame ("UPDATE Config SET varvalue = '" . CODE_VERSION . "' WHERE varname = 'DB_VERSION'", end ($batch));
		$this->assertNull (getUpgradeBatch ('0.23.0'));
	}
}
