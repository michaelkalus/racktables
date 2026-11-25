<?php

// Make sure the search breadcrumb link reproduces the same search and that
// the search terms stay HTML-escaped both in the link text and in the href.
class ShowPathAndSearchTest extends RTTestCase
{
	const TERMS = 'a&b #c 50%41 d+e "f" \'g\' <b>h</b> ' . "\xC5\xBElu\xC5\xA5";

	/**
	 * showPathAndSearch() declares a nested function, so it can be called
	 * only once per process.
	 *
	 * @group small
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testSearchBreadcrumb ()
	{
		global $pageno, $tabno, $sic;
		$saved = array ($pageno, $tabno, $sic, $_REQUEST);
		// Mimic what transformRequestData() does for a search request.
		$pageno = 'search';
		$tabno = 'default';
		$sic = array ('page' => 'search', 'q' => self::TERMS);
		$_REQUEST = array ('page' => 'search', 'q' => htmlspecialchars (self::TERMS, ENT_QUOTES, 'UTF-8'));
		$output = getOutputOf ('showPathAndSearch', 'search', 'default');
		list ($pageno, $tabno, $sic, $_REQUEST) = $saved;

		$this->assertStringNotContainsString ('<b>', $output);
		$this->assertEquals (1, preg_match ("/<a href='([^']*page=search[^']*)'>([^<]*)<\\/a>/", $output, $m));
		$escaped = htmlspecialchars (self::TERMS, ENT_QUOTES, 'UTF-8');
		$this->assertEquals ("search results for '{$escaped}'", $m[2]);
		$href = html_entity_decode ($m[1], ENT_QUOTES, 'UTF-8');
		$this->assertStringNotContainsString ('#', $href);
		$this->assertStringStartsWith ('index.php?', $href);
		parse_str (substr ($href, strlen ('index.php?')), $params);
		$this->assertEquals ('search', $params['page']);
		$this->assertEquals (self::TERMS, $params['q']);
	}

	public function providerNonScalarTerms ()
	{
		return array
		(
			array (array ('x')),
			array (array ('a' => array ('b' => 'x'))),
		);
	}

	/**
	 * @group small
	 * @dataProvider providerNonScalarTerms
	 */
	public function testSearchTitleNonScalar ($q)
	{
		global $sic;
		$saved = array ($sic, $_REQUEST);
		$sic = array ('page' => 'search', 'q' => $q);
		$_REQUEST = array ('page' => 'search', 'q' => $q);
		$title = dynamic_title_decoder ('search');
		list ($sic, $_REQUEST) = $saved;
		// The malformed request must be rejected as an invalid argument
		// and must not leave a non-scalar value for the breadcrumb link.
		$this->assertEquals ('dynamic_title_decoder() failure', $title['name']);
		$this->assertEquals (array(), $title['params']);
	}
}
