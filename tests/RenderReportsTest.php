<?php

require_once '../wwwroot/inc/navigation.php';

// Make sure renderDepot does not throw any exceptions
class RenderReportsTest extends RTTestCase
{
	/**
	 * @group small
	 * @dataProvider providerReportFunctions
	 */
	public function testHandlerPrints ($pageno, $tabno, $funcname)
	{
		global $interface_requires;
		requireExtraFiles ($interface_requires, $pageno, $tabno);
		$output = getOutputOf ($funcname);
		$this->assertNotEquals ('', $output);
		if ($tabno == 'integrity')
			$this->assertEquals ('No integrity violations found', strip_tags ($output));
	}

	/**
	 * @group small
	 */
	public function testIntegrityRackWithoutRow ()
	{
		global $interface_requires;
		requireExtraFiles ($interface_requires, 'reports', 'integrity');
		$rack_name = $this->myString ('rack');
		$rack_id = commitAddObject ($rack_name, NULL, 1560, NULL);
		try
		{
			$output = getOutputOf ('renderDataIntegrityReport');
		}
		finally
		{
			commitDeleteObject ($rack_id);
		}
		$this->assertStringContainsString ('Racks without a row (1)', $output);
		$this->assertStringContainsString ($rack_name, $output);
	}

	// The tags, files and zero-U objects of a rack without a row belong to an
	// existing rack, they must not be reported as orphans.
	/**
	 * @group small
	 */
	public function testIntegrityRackWithoutRowRelatives ()
	{
		global $interface_requires;
		requireExtraFiles ($interface_requires, 'reports', 'integrity');
		$rack_id = commitAddObject ($this->myString ('rack'), NULL, 1560, NULL);
		$object_id = commitAddObject ($this->myString ('switch'), NULL, 8, NULL);
		usePreparedInsertBlade ('TagTree', array ('tag' => $this->myString ('tag')));
		$tag_id = lastInsertID();
		$file_id = commitAddFile ($this->myString ('file'), 'text/plain', 'test', '');
		try
		{
			addTagForEntity ('rack', $rack_id, $tag_id);
			commitLinkEntities ('rack', $rack_id, 'object', $object_id);
			usePreparedInsertBlade ('FileLink', array ('file_id' => $file_id, 'entity_type' => 'rack', 'entity_id' => $rack_id));
			$output = getOutputOf ('renderDataIntegrityReport');
		}
		finally
		{
			commitDeleteObject ($object_id);
			commitDeleteObject ($rack_id);
			commitDeleteFile ($file_id);
			usePreparedDeleteBlade ('TagTree', array ('id' => $tag_id));
		}
		$this->assertStringContainsString ('Racks without a row (1)', $output);
		$this->assertStringNotContainsString ('Missing', $output);
	}

	public function providerReportFunctions ()
	{
		$ret = array();
		global $tabhandler;
		foreach ($tabhandler['reports'] as $tabno => $funcname)
			$ret[] = array ('reports', $tabno, $funcname);
		return $ret;
	}
}
