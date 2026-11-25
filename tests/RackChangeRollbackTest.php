<?php

// A rack update or an object deletion that fails must leave the database
// exactly as it was before the operation.
class RackChangeRollbackTest extends RTTestCase
{
	protected static $rowa_id, $rowb_id, $tag_id, $file_id;
	protected $rack_id, $other_rack_id;

	public static function setUpBeforeClass () : void
	{
		global $taglist;
		self::$rowa_id = commitAddObject (self::myStringStatic ('row a', __CLASS__), NULL, 1561, NULL);
		self::$rowb_id = commitAddObject (self::myStringStatic ('row b', __CLASS__), NULL, 1561, NULL);
		usePreparedInsertBlade ('TagTree', array ('tag' => self::myStringStatic ('tag', __CLASS__)));
		self::$tag_id = lastInsertID();
		$taglist = addTraceToNodes (getTagList());
		self::$file_id = commitAddFile (self::myStringStatic ('file', __CLASS__), 'text/plain', 'test', '');
	}

	public static function tearDownAfterClass () : void
	{
		commitDeleteFile (self::$file_id);
		commitDeleteObject (self::$rowa_id);
		commitDeleteObject (self::$rowb_id);
		usePreparedDeleteBlade ('TagTree', array ('id' => self::$tag_id));
	}

	public function setUp () : void
	{
		$this->rack_id = $this->addRack ('rack', self::$rowa_id, 1);
		$this->other_rack_id = $this->addRack ('other rack', self::$rowb_id, 1);
		usePreparedInsertBlade
		(
			'FileLink',
			array ('file_id' => self::$file_id, 'entity_type' => 'rack', 'entity_id' => $this->rack_id)
		);
		foreach (array ('front', 'interior', 'rear') as $atom)
			usePreparedInsertBlade
			(
				'RackSpace',
				array ('rack_id' => $this->rack_id, 'unit_no' => 1, 'atom' => $atom, 'state' => 'A')
			);
	}

	public function tearDown () : void
	{
		commitDeleteRack ($this->rack_id);
		commitDeleteRack ($this->other_rack_id);
	}

	protected function addRack ($name, $row_id, $sort_order)
	{
		$rack_id = commitAddObject ($this->myString ($name), NULL, 1560, $this->myString ($name), array (self::$tag_id));
		commitUpdateAttrValue ($rack_id, 27, 42);
		commitUpdateAttrValue ($rack_id, 29, $sort_order);
		commitLinkEntities ('row', $row_id, 'rack', $rack_id);
		return $rack_id;
	}

	protected function fetchRows ($query, $args)
	{
		$result = usePreparedSelectBlade ($query, $args);
		return $result->fetchAll (PDO::FETCH_ASSOC);
	}

	// everything the operations under test may change about a rack
	protected function snapshot ($rack_id)
	{
		return array
		(
			'Object' => $this->fetchRows ('SELECT * FROM Object WHERE id = ?', array ($rack_id)),
			'EntityLink' => $this->fetchRows
			(
				'SELECT * FROM EntityLink WHERE (parent_entity_type = "rack" AND parent_entity_id = ?) ' .
				'OR (child_entity_type = "rack" AND child_entity_id = ?) ORDER BY id',
				array ($rack_id, $rack_id)
			),
			'AttributeValue' => $this->fetchRows ('SELECT * FROM AttributeValue WHERE object_id = ? ORDER BY attr_id', array ($rack_id)),
			'TagStorage' => $this->fetchRows ('SELECT entity_realm, entity_id, tag_id FROM TagStorage WHERE entity_id = ? ORDER BY entity_realm, tag_id', array ($rack_id)),
			'FileLink' => $this->fetchRows ('SELECT * FROM FileLink WHERE entity_id = ? ORDER BY id', array ($rack_id)),
			'RackSpace' => $this->fetchRows ('SELECT * FROM RackSpace WHERE rack_id = ? ORDER BY unit_no, atom', array ($rack_id)),
			'ObjectHistory' => $this->fetchRows ('SELECT COUNT(*) AS cnt FROM ObjectHistory WHERE id = ?', array ($rack_id)),
		);
	}

	protected function assertUnchanged ($before, $rack_id)
	{
		$this->assertEquals ($before, $this->snapshot ($rack_id));
		$this->assertEquals ($this->myString ('rack'), $before['Object'][0]['name']);
		$this->assertCount (1, $before['TagStorage']);
		$this->assertCount (1, $before['FileLink']);
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testUpdateRackToMissingRow ()
	{
		$before = $this->snapshot ($this->rack_id);
		// find an ID that is not a row
		$result = usePreparedSelectBlade ('SELECT MAX(id) + 1000 FROM Object');
		$missing_row_id = $result->fetchColumn();
		unset ($result);
		try
		{
			commitUpdateRack ($this->rack_id, $missing_row_id, $this->myString ('new name'), 42, 'no', NULL, NULL);
			$this->fail ('the update must fail');
		}
		catch (EntityNotFoundException $e)
		{
		}
		$this->assertUnchanged ($before, $this->rack_id);
	}

	/**
	 * @group small
	 */
	public function testUpdateRackFailingAfterRowChange ()
	{
		$before = $this->snapshot ($this->rack_id);
		$before_other = $this->snapshot ($this->other_rack_id);
		try
		{
			// the asset number is already used by the other rack
			commitUpdateRack ($this->rack_id, self::$rowb_id, $this->myString ('new name'), 42, 'no', $this->myString ('other rack'), NULL);
			$this->fail ('the update must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		$this->assertUnchanged ($before, $this->rack_id);
		$this->assertEquals ($before_other, $this->snapshot ($this->other_rack_id));
	}

	/**
	 * @group small
	 */
	public function testUpdateRackSuccess ()
	{
		commitUpdateRack ($this->rack_id, self::$rowb_id, $this->myString ('new name'), 40, 'no', NULL, NULL);
		$rack = spotEntity ('rack', $this->rack_id, TRUE);
		$this->assertEquals (self::$rowb_id, $rack['row_id']);
		$this->assertEquals ($this->myString ('new name'), $rack['name']);
		$this->assertEquals (40, $rack['height']);
		// placed at the end of the new row, after the other rack
		$this->assertEquals
		(
			array (array ('uint_value' => 2)),
			$this->fetchRows ('SELECT uint_value FROM AttributeValue WHERE object_id = ? AND attr_id = 29', array ($this->rack_id))
		);
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testDeleteRackObjectWithRackSpace ()
	{
		$before = $this->snapshot ($this->rack_id);
		try
		{
			// rack space still refers to the rack
			commitDeleteObject ($this->rack_id);
			$this->fail ('the delete must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		$this->assertUnchanged ($before, $this->rack_id);
	}

	protected function setOuterComment ($comment)
	{
		usePreparedUpdateBlade ('Object', array ('comment' => $comment), array ('id' => $this->other_rack_id));
	}

	protected function assertOuterComment ($comment)
	{
		$this->assertEquals
		(
			array (array ('comment' => $comment)),
			$this->fetchRows ('SELECT comment FROM Object WHERE id = ?', array ($this->other_rack_id))
		);
	}

	/**
	 * @group small
	 */
	public function testUpdateRackFailingInOuterTransaction ()
	{
		global $dbxlink;
		$before = $this->snapshot ($this->rack_id);
		$dbxlink->beginTransaction();
		$this->setOuterComment ('outer');
		try
		{
			commitUpdateRack ($this->rack_id, self::$rowb_id, $this->myString ('new name'), 42, 'no', $this->myString ('other rack'), NULL);
			$this->fail ('the update must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		// the caller handles the error and keeps its own work
		$this->assertTrue (isTransactionActive());
		$dbxlink->commit();
		$this->assertUnchanged ($before, $this->rack_id);
		$this->assertOuterComment ('outer');
	}

	/**
	 * @group small
	 */
	public function testUpdateRackInOuterTransaction ()
	{
		global $dbxlink;
		$before = $this->snapshot ($this->rack_id);
		$dbxlink->beginTransaction();
		commitUpdateRack ($this->rack_id, self::$rowb_id, $this->myString ('new name'), 40, 'no', NULL, NULL);
		// the function must not have committed the outer transaction
		$this->assertTrue (isTransactionActive());
		$dbxlink->rollBack();
		$this->assertUnchanged ($before, $this->rack_id);
	}

	/**
	 * @group small
	 */
	public function testDeleteRackObjectFailingInOuterTransaction ()
	{
		global $dbxlink;
		$before = $this->snapshot ($this->rack_id);
		$dbxlink->beginTransaction();
		$this->setOuterComment ('outer');
		try
		{
			commitDeleteObject ($this->rack_id);
			$this->fail ('the delete must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		// the caller handles the error and keeps its own work
		$this->assertTrue (isTransactionActive());
		$dbxlink->commit();
		$this->assertUnchanged ($before, $this->rack_id);
		$this->assertOuterComment ('outer');
	}

	/**
	 * @group small
	 */
	public function testUndoAfterServerRollback ()
	{
		global $dbxlink;
		$dbxlink->beginTransaction();
		$savepoint = beginAtomicChange ('testUndoAfterServerRollback');
		$this->assertEquals ('testUndoAfterServerRollback', $savepoint);
		// the server rolls back the whole transaction (e.g. on a deadlock)
		// and the savepoint no longer exists
		$dbxlink->exec ('ROLLBACK');
		// this must not throw an exception that hides the original error
		endAtomicChange ($savepoint, FALSE);
		try
		{
			$dbxlink->rollBack();
		}
		catch (PDOException $e)
		{
		}
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testDeleteObjectInOuterTransaction ()
	{
		global $dbxlink;
		$object_id = commitAddObject ($this->myString ('object'), NULL, 4, NULL);
		$dbxlink->beginTransaction();
		commitDeleteObject ($object_id);
		// the function must not have committed the outer transaction
		$this->assertTrue (isTransactionActive());
		$dbxlink->rollBack();
		$this->assertCount (1, $this->fetchRows ('SELECT id FROM Object WHERE id = ?', array ($object_id)));
		commitDeleteObject ($object_id);
		$this->assertCount (0, $this->fetchRows ('SELECT id FROM Object WHERE id = ?', array ($object_id)));
	}
}
