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

	public function providerReportFunctions ()
	{
		$ret = array();
		global $tabhandler;
		foreach ($tabhandler['reports'] as $tabno => $funcname)
			$ret[] = array ('reports', $tabno, $funcname);
		return $ret;
	}
}
