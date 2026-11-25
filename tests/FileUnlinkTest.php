<?php

// The "Unlink file" operation of the files tab of an entity must only remove
// a link of a file to that entity. The file manager may remove any link.
class FileUnlinkTest extends RTTestCase
{
	protected static $objtype_id;
	protected static $objecta_id, $objectb_id, $rack_id;
	protected static $file_id;

	public static function setUpBeforeClass () : void
	{
		// add sample data
		usePreparedInsertBlade ('Dictionary', array ('chapter_id' => 1, 'dict_value' => self::myStringStatic ('type', __CLASS__)));
		self::$objtype_id = lastInsertID ();
		commitSupplementOPC (self::$objtype_id, self::$objtype_id);
		self::$objecta_id = commitAddObject (self::myStringStatic ('object a', __CLASS__), NULL, self::$objtype_id, NULL);
		self::$objectb_id = commitAddObject (self::myStringStatic ('object b', __CLASS__), NULL, self::$objtype_id, NULL);
		self::$rack_id = commitAddObject (self::myStringStatic ('rack', __CLASS__), NULL, 1560, NULL);
		self::$file_id = commitAddFile (self::myStringStatic ('file', __CLASS__), 'text/plain', 'test', '');
	}

	public static function tearDownAfterClass () : void
	{
		// remove sample data (the links are removed with the file)
		commitDeleteFile (self::$file_id);
		commitDeleteObject (self::$objecta_id);
		commitDeleteObject (self::$objectb_id);
		commitDeleteObject (self::$rack_id);
		commitReduceOPC (self::$objtype_id, self::$objtype_id);
		usePreparedDeleteBlade ('Dictionary', array ('dict_key' => self::$objtype_id));
	}

	public function tearDown () : void
	{
		global $sic;
		foreach (array ('object_id', 'rack_id', 'id', 'link_id') as $argname)
			unset ($sic[$argname], $_REQUEST[$argname]);
		// delete any links created during the test
		usePreparedDeleteBlade ('FileLink', array ('file_id' => self::$file_id));
	}

	protected function linkAndGetID ($entity_type, $entity_id)
	{
		usePreparedInsertBlade
		(
			'FileLink',
			array ('file_id' => self::$file_id, 'entity_type' => $entity_type, 'entity_id' => $entity_id)
		);
		return lastInsertID ();
	}

	protected function linkExists ($link_id)
	{
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM FileLink WHERE id = ?', array ($link_id));
		return $result->fetchColumn () == 1;
	}

	// run the ophandler the way index.php?module=redirect does
	protected function runUnlink ($pageno_, $tabno_, $bypass, $link_id)
	{
		global $ophandler, $pageno, $tabno, $op, $sic;
		$pageno = $pageno_;
		$tabno = $tabno_;
		$op = 'unlinkFile';
		foreach ($bypass as $argname => $argvalue)
			$sic[$argname] = $_REQUEST[$argname] = $argvalue;
		$sic['link_id'] = $_REQUEST['link_id'] = $link_id;
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function assertUnlinkRefused ($pageno_, $bypass, $link_id)
	{
		try
		{
			$this->runUnlink ($pageno_, 'files', $bypass, $link_id);
			$refused = FALSE;
		}
		catch (InvalidRequestArgException $e)
		{
			$refused = TRUE;
		}
		$this->assertTrue ($this->linkExists ($link_id), 'the link must not be deleted');
		$this->assertTrue ($refused, 'the request must be refused');
	}

	/**
	 * @group small
	 */
	public function testUnlinkOwnFileOfObject ()
	{
		$link_id = $this->linkAndGetID ('object', self::$objecta_id);
		$this->runUnlink ('object', 'files', array ('object_id' => self::$objecta_id), $link_id);
		$this->assertFalse ($this->linkExists ($link_id));
	}

	/**
	 * @group small
	 */
	public function testUnlinkOwnFileOfRack ()
	{
		$link_id = $this->linkAndGetID ('rack', self::$rack_id);
		$this->runUnlink ('rack', 'files', array ('rack_id' => self::$rack_id), $link_id);
		$this->assertFalse ($this->linkExists ($link_id));
	}

	/**
	 * @group small
	 */
	public function testUnlinkOwnFileOfIPv4Network ()
	{
		// the network does not need to exist for the link to be removed
		$link_id = $this->linkAndGetID ('ipv4net', self::$objecta_id);
		$this->runUnlink ('ipv4net', 'files', array ('id' => self::$objecta_id), $link_id);
		$this->assertFalse ($this->linkExists ($link_id));
	}

	/**
	 * @group small
	 */
	public function testUnlinkFileOfOtherObjectRefused ()
	{
		$link_id = $this->linkAndGetID ('object', self::$objectb_id);
		$this->assertUnlinkRefused ('object', array ('object_id' => self::$objecta_id), $link_id);
	}

	/**
	 * @group small
	 */
	public function testUnlinkFileOfOtherRealmRefused ()
	{
		// same numeric ID, different realm
		$link_id = $this->linkAndGetID ('ipv4net', self::$objecta_id);
		$this->assertUnlinkRefused ('object', array ('object_id' => self::$objecta_id), $link_id);
		$link_id = $this->linkAndGetID ('object', self::$rack_id);
		$this->assertUnlinkRefused ('rack', array ('rack_id' => self::$rack_id), $link_id);
	}

	/**
	 * @group small
	 */
	public function testUnlinkMissingLinkRefused ()
	{
		$link_id = $this->linkAndGetID ('object', self::$objecta_id);
		usePreparedDeleteBlade ('FileLink', array ('id' => $link_id));
		$this->expectException (InvalidRequestArgException::class);
		$this->runUnlink ('object', 'files', array ('object_id' => self::$objecta_id), $link_id);
	}

	/**
	 * @group small
	 */
	public function testUnlinkAnyFileInFileManager ()
	{
		$link_id = $this->linkAndGetID ('object', self::$objectb_id);
		$this->runUnlink ('files', 'manage', array (), $link_id);
		$this->assertFalse ($this->linkExists ($link_id));
	}
}
