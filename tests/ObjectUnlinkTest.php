<?php

// The "Unlink container" operation of the object edit tab must only remove
// an object-to-object link, in which the object in the request is the child.
class ObjectUnlinkTest extends RTTestCase
{
	protected static $objtype_id;
	protected static $objecta_id, $objectb_id, $objectc_id;
	protected static $rack_id, $row_id;

	public static function setUpBeforeClass () : void
	{
		// add sample data
		usePreparedInsertBlade ('Dictionary', array ('chapter_id' => 1, 'dict_value' => self::myStringStatic ('type', __CLASS__)));
		self::$objtype_id = lastInsertID ();
		commitSupplementOPC (self::$objtype_id, self::$objtype_id);
		self::$objecta_id = commitAddObject (self::myStringStatic ('object a', __CLASS__), NULL, self::$objtype_id, NULL);
		self::$objectb_id = commitAddObject (self::myStringStatic ('object b', __CLASS__), NULL, self::$objtype_id, NULL);
		self::$objectc_id = commitAddObject (self::myStringStatic ('object c', __CLASS__), NULL, self::$objtype_id, NULL);
		self::$rack_id = commitAddObject (self::myStringStatic ('rack', __CLASS__), NULL, 1560, NULL);
		self::$row_id = commitAddObject (self::myStringStatic ('row', __CLASS__), NULL, 1561, NULL);
	}

	public static function tearDownAfterClass () : void
	{
		// remove sample data
		commitDeleteObject (self::$objecta_id);
		commitDeleteObject (self::$objectb_id);
		commitDeleteObject (self::$objectc_id);
		commitDeleteObject (self::$rack_id);
		commitDeleteObject (self::$row_id);
		commitReduceOPC (self::$objtype_id, self::$objtype_id);
		usePreparedDeleteBlade ('Dictionary', array ('dict_key' => self::$objtype_id));
	}

	public function tearDown () : void
	{
		global $sic;
		unset ($sic['object_id'], $sic['link_id'], $_REQUEST['object_id'], $_REQUEST['link_id']);
		// delete any links created during the test
		$ids = array (self::$objecta_id, self::$objectb_id, self::$objectc_id, self::$rack_id, self::$row_id);
		usePreparedExecuteBlade
		(
			'DELETE FROM EntityLink WHERE parent_entity_id IN (?,?,?,?,?) OR child_entity_id IN (?,?,?,?,?)',
			array_merge ($ids, $ids)
		);
	}

	protected function linkAndGetID ($parent_entity_type, $parent_entity_id, $child_entity_type, $child_entity_id)
	{
		commitLinkEntities ($parent_entity_type, $parent_entity_id, $child_entity_type, $child_entity_id);
		return lastInsertID ();
	}

	protected function linkExists ($link_id)
	{
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM EntityLink WHERE id = ?', array ($link_id));
		return $result->fetchColumn () == 1;
	}

	// run the ophandler the way index.php?module=redirect does
	protected function runUnlink ($object_id, $link_id)
	{
		global $ophandler, $pageno, $tabno, $op, $sic;
		$pageno = 'object';
		$tabno = 'edit';
		$op = 'unlinkObjects';
		$sic['object_id'] = $_REQUEST['object_id'] = $object_id;
		$sic['link_id'] = $_REQUEST['link_id'] = $link_id;
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function assertUnlinkRefused ($object_id, $link_id)
	{
		try
		{
			$this->runUnlink ($object_id, $link_id);
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
	public function testUnlinkOwnContainer ()
	{
		$link_id = $this->linkAndGetID ('object', self::$objecta_id, 'object', self::$objectb_id);
		$this->runUnlink (self::$objectb_id, $link_id);
		$this->assertFalse ($this->linkExists ($link_id));
	}

	/**
	 * @group small
	 */
	public function testUnlinkRackFromRowRefused ()
	{
		$link_id = $this->linkAndGetID ('row', self::$row_id, 'rack', self::$rack_id);
		$this->assertUnlinkRefused (self::$objectb_id, $link_id);
	}

	/**
	 * @group small
	 */
	public function testUnlinkOtherObjectContainerRefused ()
	{
		$link_id = $this->linkAndGetID ('object', self::$objecta_id, 'object', self::$objectc_id);
		$this->assertUnlinkRefused (self::$objectb_id, $link_id);
	}

	/**
	 * @group small
	 */
	public function testUnlinkContainedObjectRefused ()
	{
		// the object edit tab offers to unlink the containers of the object only
		$link_id = $this->linkAndGetID ('object', self::$objectb_id, 'object', self::$objectc_id);
		$this->assertUnlinkRefused (self::$objectb_id, $link_id);
	}

	/**
	 * @group small
	 */
	public function testUnlinkMissingLinkRefused ()
	{
		$link_id = $this->linkAndGetID ('object', self::$objecta_id, 'object', self::$objectb_id);
		commitUnlinkEntitiesByLinkID ($link_id);
		$this->expectException (InvalidRequestArgException::class);
		$this->runUnlink (self::$objectb_id, $link_id);
	}
}
