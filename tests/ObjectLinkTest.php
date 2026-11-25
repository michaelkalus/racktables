<?php

// The "Select container" operation of the object edit tab must only create
// an object-to-object link, in which the object in the request is the child.
class ObjectLinkTest extends RTTestCase
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
		foreach (array ('object_id', 'parent_entity_type', 'parent_entity_id', 'child_entity_type', 'child_entity_id') as $name)
			unset ($sic[$name], $_REQUEST[$name]);
		// delete any links created during the test
		$ids = self::getIDs ();
		usePreparedExecuteBlade
		(
			'DELETE FROM EntityLink WHERE parent_entity_id IN (?,?,?,?,?) OR child_entity_id IN (?,?,?,?,?)',
			array_merge ($ids, $ids)
		);
	}

	protected static function getIDs ()
	{
		return array (self::$objecta_id, self::$objectb_id, self::$objectc_id, self::$rack_id, self::$row_id);
	}

	protected function countLinks ()
	{
		$ids = self::getIDs ();
		$result = usePreparedSelectBlade
		(
			'SELECT COUNT(*) FROM EntityLink WHERE parent_entity_id IN (?,?,?,?,?) OR child_entity_id IN (?,?,?,?,?)',
			array_merge ($ids, $ids)
		);
		return $result->fetchColumn ();
	}

	protected function linkExists ($parent_entity_type, $parent_entity_id, $child_entity_type, $child_entity_id)
	{
		$result = usePreparedSelectBlade
		(
			'SELECT COUNT(*) FROM EntityLink WHERE parent_entity_type = ? AND parent_entity_id = ? AND child_entity_type = ? AND child_entity_id = ?',
			array ($parent_entity_type, $parent_entity_id, $child_entity_type, $child_entity_id)
		);
		return $result->fetchColumn () == 1;
	}

	// run the ophandler the way index.php?module=redirect does
	protected function runLink ($object_id, $parent_entity_type, $parent_entity_id, $child_entity_type, $child_entity_id)
	{
		global $ophandler, $pageno, $tabno, $op, $sic;
		$pageno = 'object';
		$tabno = 'edit';
		$op = 'linkObjects';
		$sic['object_id'] = $_REQUEST['object_id'] = $object_id;
		$sic['parent_entity_type'] = $_REQUEST['parent_entity_type'] = $parent_entity_type;
		$sic['parent_entity_id'] = $_REQUEST['parent_entity_id'] = $parent_entity_id;
		$sic['child_entity_type'] = $_REQUEST['child_entity_type'] = $child_entity_type;
		$sic['child_entity_id'] = $_REQUEST['child_entity_id'] = $child_entity_id;
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function assertLinkRefused ($object_id, $parent_entity_type, $parent_entity_id, $child_entity_type, $child_entity_id)
	{
		$before = $this->countLinks ();
		try
		{
			$this->runLink ($object_id, $parent_entity_type, $parent_entity_id, $child_entity_type, $child_entity_id);
			$refused = FALSE;
		}
		catch (InvalidRequestArgException $e)
		{
			$refused = TRUE;
		}
		$this->assertEquals ($before, $this->countLinks (), 'no link must be created');
		$this->assertTrue ($refused, 'the request must be refused');
	}

	/**
	 * @group small
	 */
	public function testLinkOwnContainer ()
	{
		// this is what the container selection popup sends
		$this->runLink (self::$objectb_id, 'object', self::$objecta_id, 'object', self::$objectb_id);
		$this->assertTrue ($this->linkExists ('object', self::$objecta_id, 'object', self::$objectb_id));
	}

	/**
	 * @group small
	 */
	public function testLinkContainerOfContainer ()
	{
		// the object may itself contain other objects
		commitLinkEntities ('object', self::$objectb_id, 'object', self::$objectc_id);
		$this->runLink (self::$objectb_id, 'object', self::$objecta_id, 'object', self::$objectb_id);
		$this->assertTrue ($this->linkExists ('object', self::$objecta_id, 'object', self::$objectb_id));
		$this->assertTrue ($this->linkExists ('object', self::$objectb_id, 'object', self::$objectc_id));
	}

	/**
	 * @group small
	 */
	public function testLinkOtherObjectToRackRefused ()
	{
		$this->assertLinkRefused (self::$objectb_id, 'rack', self::$rack_id, 'object', self::$objectc_id);
	}

	/**
	 * @group small
	 */
	public function testLinkOwnObjectToRackRefused ()
	{
		// the edit tab offers object containers only
		$this->assertLinkRefused (self::$objectb_id, 'rack', self::$rack_id, 'object', self::$objectb_id);
	}

	/**
	 * @group small
	 */
	public function testLinkRackToRowRefused ()
	{
		$this->assertLinkRefused (self::$objectb_id, 'row', self::$row_id, 'rack', self::$rack_id);
	}

	/**
	 * @group small
	 */
	public function testLinkOtherObjectRefused ()
	{
		$this->assertLinkRefused (self::$objectb_id, 'object', self::$objecta_id, 'object', self::$objectc_id);
	}

	/**
	 * @group small
	 */
	public function testLinkContainedObjectRefused ()
	{
		// the edit tab offers to set the containers of the object only
		$this->assertLinkRefused (self::$objectb_id, 'object', self::$objectb_id, 'object', self::$objectc_id);
	}

	/**
	 * @group small
	 */
	public function testLinkCircularReference ()
	{
		commitLinkEntities ('object', self::$objectb_id, 'object', self::$objectc_id);
		$this->expectException (RackTablesError::class);
		$this->expectExceptionMessage ('Circular reference for object ' . self::$objectc_id);
		$this->runLink (self::$objectb_id, 'object', self::$objectc_id, 'object', self::$objectb_id);
	}

	/**
	 * @group small
	 */
	public function testLinkDuplicate ()
	{
		commitLinkEntities ('object', self::$objecta_id, 'object', self::$objectb_id);
		$this->expectException (RTDatabaseError::class);
		$this->runLink (self::$objectb_id, 'object', self::$objecta_id, 'object', self::$objectb_id);
	}
}
