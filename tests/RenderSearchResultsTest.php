<?php

// Make sure renderSearchResults() escapes the search terms it prints.
class RenderSearchResultsTest extends RTTestCase
{
	const TERMS = 'a<b>c</b>"d\'e&f';
	const ESCAPED = 'a&lt;b&gt;c&lt;/b&gt;&quot;d&#039;e&amp;f';

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
}
