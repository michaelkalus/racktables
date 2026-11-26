<?php

// An unknown value of the "andor" request argument must not break the page.
class CellFilterAndorTest extends RTTestCase
{
	private $saved_request;
	private $saved_pageno;

	public function setUp () : void
	{
		global $pageno;
		@session_start();
		$this->saved_request = $_REQUEST;
		$this->saved_pageno = $pageno;
		$pageno = $this->myString ('page');
	}

	public function tearDown () : void
	{
		global $pageno;
		unset ($_SESSION[$pageno]);
		$_REQUEST = $this->saved_request;
		$pageno = $this->saved_pageno;
	}

	/**
	 * @group small
	 * @dataProvider providerInvalid
	 */
	public function testInvalidValueIsIgnored ($value)
	{
		$_REQUEST['andor'] = $value;
		$filter = getCellFilter();
		$this->assertTrue (is_array ($filter));
		$this->assertTrue ($filter['is_empty']);
		$this->assertSame (array(), $filter['expression']);
		$this->assertSame (getConfigVar ('FILTER_DEFAULT_ANDOR'), $filter['andor']);
		$this->assertSame ('', $filter['urlextra']);
		$this->assertFalse (isset ($_REQUEST['andor']));
	}

	public function providerInvalid ()
	{
		return array
		(
			array ('x'),
			array (''),
			array ('AND'),
			array (array ('and')),
		);
	}

	/**
	 * @group small
	 * @dataProvider providerValid
	 */
	public function testValidValueIsKept ($value)
	{
		global $pageno;
		$_REQUEST['andor'] = $value;
		$filter = getCellFilter();
		$this->assertSame ($value, $_SESSION[$pageno]['filter']['andor']);
		$this->assertTrue ($filter['is_empty']);
	}

	public function providerValid ()
	{
		return array
		(
			array ('and'),
			array ('or'),
		);
	}
}
