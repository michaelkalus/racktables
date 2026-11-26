<?php
require_once '../wwwroot/inc/slb2-interface.php';

// Text that comes from the request or from the SLB configuration columns is
// escaped where it is printed.
class MoreOutputEscapingTest extends RTTestCase
{
	const TEXT = "</textarea><i>x73 'q' \"d\" & {tag}";

	/**
	 * @group small
	 */
	public function testCellFilterExtraText ()
	{
		$preselect = array
		(
			'tagidlist' => array(),
			'tnamelist' => array(),
			'pnamelist' => array(),
			'negatedlist' => array(),
			'andor' => 'and',
			'text' => '',
			'extratext' => self::TEXT,
			'expression' => array(),
			'urlextra' => '',
			'is_empty' => FALSE,
		);
		$output = getOutputOf ('renderCellFilterPortlet', $preselect, 'object');
		$this->assertStringNotContainsString ('<i>x73', $output);
		$this->assertStringContainsString ('&lt;/textarea&gt;&lt;i&gt;x73 &#039;q&#039; &quot;d&quot; &amp; {tag}', $output);
		// the same text as a JavaScript string inside the onclick attribute
		$this->assertSame (1, preg_match ('/onclick="textifyCellFilter\(this, ([^"]*)\); return false"/', $output, $m));
		$this->assertSame (self::TEXT, json_decode (htmlspecialchars_decode ($m[1], ENT_QUOTES)));
	}

	/**
	 * @group small
	 */
	public function testPopupSLBConfig ()
	{
		$output = getPopupSLBConfig (array ('vsconfig' => self::TEXT, 'rsconfig' => '<b>rs</b>'));
		$this->assertStringNotContainsString ('<i>x73', $output);
		$this->assertStringNotContainsString ('<b>rs', $output);
		$this->assertStringContainsString ('&lt;b&gt;rs&lt;/b&gt;', $output);
	}
}
