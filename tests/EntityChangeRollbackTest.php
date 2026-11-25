<?php

// Adding, updating and deleting locations, rows and racks must refuse a parent
// location that does not exist and must leave the database exactly as it was
// when the operation fails (see also RackChangeRollbackTest).
class EntityChangeRollbackTest extends RTTestCase
{
	protected static $tag_id, $file_id;
	protected $location_id, $child_location_id, $row_id, $rack_ids;
	protected $missing_id;
	protected $saved_request, $saved_sic, $saved_pageno, $saved_hook;

	public static function setUpBeforeClass () : void
	{
		global $taglist;
		usePreparedInsertBlade ('TagTree', array ('tag' => self::myStringStatic ('tag', __CLASS__)));
		self::$tag_id = lastInsertID();
		$taglist = addTraceToNodes (getTagList());
		self::$file_id = commitAddFile (self::myStringStatic ('file', __CLASS__), 'text/plain', 'test', '');
	}

	public static function tearDownAfterClass () : void
	{
		commitDeleteFile (self::$file_id);
		usePreparedDeleteBlade ('TagTree', array ('id' => self::$tag_id));
	}

	public function setUp () : void
	{
		global $sic, $pageno, $hook;
		$this->saved_request = $_REQUEST;
		$this->saved_sic = $sic;
		$this->saved_pageno = $pageno;
		$this->saved_hook = $hook;

		$this->location_id = commitAddObject ($this->myString ('location'), NULL, 1562, NULL, array (self::$tag_id));
		$this->child_location_id = commitAddObject ($this->myString ('child location'), NULL, 1562, NULL, array (self::$tag_id));
		commitLinkEntities ('location', $this->location_id, 'location', $this->child_location_id);
		$this->row_id = commitAddObject ($this->myString ('row'), NULL, 1561, NULL);
		commitLinkEntities ('location', $this->location_id, 'row', $this->row_id);
		$this->rack_ids = array();
		foreach (array (1, 2) as $sort_order)
		{
			$rack_id = commitAddObject ($this->myString ("rack {$sort_order}"), NULL, 1560, NULL, array (self::$tag_id));
			commitUpdateAttrValue ($rack_id, 27, 42);
			commitUpdateAttrValue ($rack_id, 29, $sort_order);
			commitLinkEntities ('row', $this->row_id, 'rack', $rack_id);
			foreach (array ('front', 'interior', 'rear') as $atom)
				usePreparedInsertBlade
				(
					'RackSpace',
					array ('rack_id' => $rack_id, 'unit_no' => 1, 'atom' => $atom, 'state' => 'U')
				);
			usePreparedInsertBlade ('RackThumbnail', array ('rack_id' => $rack_id, 'thumb_data' => 'test'));
			$this->rack_ids[] = $rack_id;
		}
		foreach ($this->allIDs() as $entity_id)
			usePreparedInsertBlade
			(
				'FileLink',
				array ('file_id' => self::$file_id, 'entity_type' => $this->realmOf ($entity_id), 'entity_id' => $entity_id)
			);
		// find an ID that is not an object
		$result = usePreparedSelectBlade ('SELECT MAX(id) + 1000 FROM Object');
		$this->missing_id = $result->fetchColumn();
		unset ($result);
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $hook, $dbxlink;
		$_REQUEST = $this->saved_request;
		$sic = $this->saved_sic;
		$pageno = $this->saved_pageno;
		$hook = $this->saved_hook;
		if (isTransactionActive())
			$dbxlink->rollBack();
		foreach ($this->rack_ids as $rack_id)
			usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $rack_id));
		foreach (array_merge ($this->rack_ids, array ($this->row_id, $this->child_location_id, $this->location_id)) as $entity_id)
		{
			releaseFiles ($this->realmOf ($entity_id), $entity_id);
			destroyTagsForEntity ($this->realmOf ($entity_id), $entity_id);
			if (count ($this->fetchRows ('SELECT id FROM Object WHERE id = ?', array ($entity_id))))
				commitDeleteObject ($entity_id);
		}
		// objects a failed test may have left behind
		foreach (array ('new location', 'new row', 'other row', 'object', 'new rack 1', 'new rack 2') as $name)
			foreach ($this->fetchRows ('SELECT id FROM Object WHERE name = ?', array ($this->myString ($name))) as $row)
				commitDeleteObject ($row['id']);
		setMessageBuffering (FALSE);
		$dbxlink->exec ('DROP TRIGGER IF EXISTS `EntityChangeRollbackTest-fail`');
		$GLOBALS['log_messages'] = array();
	}

	protected function allIDs ()
	{
		return array_merge (array ($this->location_id, $this->child_location_id, $this->row_id), $this->rack_ids);
	}

	protected function realmOf ($entity_id)
	{
		if ($entity_id == $this->row_id)
			return 'row';
		if (in_array ($entity_id, $this->rack_ids))
			return 'rack';
		return 'location';
	}

	protected function setRequest ($pageno, $args)
	{
		global $sic;
		$GLOBALS['pageno'] = $pageno;
		$_REQUEST = $sic = $args;
	}

	// make commitDeleteObject() fail for the given object after it has
	// already reset the object
	protected function failDeleteOf ($object_id)
	{
		global $hook;
		$hook['resetObject_hook'] = function ($id) use ($object_id)
		{
			if ($id == $object_id)
				throw new RTDatabaseError ('injected failure');
		};
	}

	protected function fetchRows ($query, $args)
	{
		$result = usePreparedSelectBlade ($query, $args);
		return $result->fetchAll (PDO::FETCH_ASSOC);
	}

	// everything the operations under test may change
	protected function snapshot ()
	{
		$ids = $this->allIDs();
		$in = questionMarks (count ($ids));
		$ids2 = array_merge ($ids, $ids);
		return array
		(
			'Object' => $this->fetchRows ("SELECT * FROM Object WHERE id IN ({$in}) ORDER BY id", $ids),
			'EntityLink' => $this->fetchRows
			(
				"SELECT * FROM EntityLink WHERE parent_entity_id IN ({$in}) OR child_entity_id IN ({$in}) ORDER BY id",
				$ids2
			),
			'AttributeValue' => $this->fetchRows ("SELECT * FROM AttributeValue WHERE object_id IN ({$in}) ORDER BY object_id, attr_id", $ids),
			'TagStorage' => $this->fetchRows ("SELECT entity_realm, entity_id, tag_id FROM TagStorage WHERE entity_id IN ({$in}) ORDER BY entity_realm, entity_id, tag_id", $ids),
			'FileLink' => $this->fetchRows ("SELECT * FROM FileLink WHERE entity_id IN ({$in}) ORDER BY id", $ids),
			'RackSpace' => $this->fetchRows ("SELECT * FROM RackSpace WHERE rack_id IN ({$in}) ORDER BY rack_id, unit_no, atom", $ids),
			'RackThumbnail' => $this->fetchRows ("SELECT rack_id FROM RackThumbnail WHERE rack_id IN ({$in}) ORDER BY rack_id", $ids),
			'ObjectHistory' => $this->fetchRows ("SELECT id, COUNT(*) AS cnt FROM ObjectHistory WHERE id IN ({$in}) GROUP BY id ORDER BY id", $ids),
		);
	}

	protected function assertUnchanged ($before)
	{
		$this->assertEquals ($before, $this->snapshot());
		$this->assertCount (5, $before['Object']);
		$this->assertCount (4, $before['TagStorage']);
		$this->assertCount (5, $before['FileLink']);
		$this->assertCount (2, $before['RackThumbnail']);
		$this->assertFalse (isTransactionActive());
	}

	protected function assertNoObjectNamed ($name)
	{
		$this->assertCount (0, $this->fetchRows ('SELECT id FROM Object WHERE name = ?', array ($name)));
	}

	protected function assertNoLinkTo ($parent_id)
	{
		$this->assertCount (0, $this->fetchRows ('SELECT id FROM EntityLink WHERE parent_entity_id = ?', array ($parent_id)));
	}

	/**
	 * @group small
	 */
	public function testAddLocationWithMissingParent ()
	{
		$this->setRequest ('rackspace', array ('name' => $this->myString ('new location'), 'parent_id' => $this->missing_id));
		try
		{
			addLocation();
			$this->fail ('the operation must fail');
		}
		catch (EntityNotFoundException $e)
		{
		}
		$this->assertNoObjectNamed ($this->myString ('new location'));
		$this->assertNoLinkTo ($this->missing_id);
	}

	/**
	 * @group small
	 */
	public function testAddRowWithMissingLocation ()
	{
		$this->setRequest ('rackspace', array ('name' => $this->myString ('new row'), 'location_id' => $this->missing_id));
		try
		{
			addRow();
			$this->fail ('the operation must fail');
		}
		catch (EntityNotFoundException $e)
		{
		}
		$this->assertNoObjectNamed ($this->myString ('new row'));
		$this->assertNoLinkTo ($this->missing_id);
	}

	/**
	 * @group small
	 */
	public function testAddLocationAndRowWithoutParent ()
	{
		$this->setRequest ('rackspace', array ('name' => $this->myString ('new location'), 'parent_id' => '0'));
		addLocation();
		$this->setRequest ('rackspace', array ('name' => $this->myString ('new row'), 'location_id' => '0'));
		addRow();
		foreach (array ('new location', 'new row') as $name)
		{
			$rows = $this->fetchRows ('SELECT id FROM Object WHERE name = ?', array ($this->myString ($name)));
			$this->assertCount (1, $rows);
			$this->assertCount
			(
				0,
				$this->fetchRows ('SELECT id FROM EntityLink WHERE child_entity_id = ?', array ($rows[0]['id']))
			);
			commitDeleteObject ($rows[0]['id']);
		}
	}

	/**
	 * @group small
	 */
	public function testUpdateLocationWithMissingParent ()
	{
		$before = $this->snapshot();
		$this->setRequest
		(
			'location',
			array
			(
				'location_id' => $this->child_location_id,
				'parent_id' => $this->missing_id,
				'name' => $this->myString ('new name'),
				'comment' => 'new comment',
				'num_attrs' => '0',
			)
		);
		try
		{
			updateLocation();
			$this->fail ('the operation must fail');
		}
		catch (EntityNotFoundException $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testUpdateRowWithMissingLocation ()
	{
		$before = $this->snapshot();
		$this->setRequest
		(
			'row',
			array
			(
				'row_id' => $this->row_id,
				'location_id' => $this->missing_id,
				'name' => $this->myString ('new name'),
				'num_attrs' => '0',
			)
		);
		try
		{
			updateRow();
			$this->fail ('the operation must fail');
		}
		catch (EntityNotFoundException $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testUpdateLocationFailingLate ()
	{
		$before = $this->snapshot();
		// num_attrs is missing
		$this->setRequest
		(
			'location',
			array
			(
				'location_id' => $this->child_location_id,
				'parent_id' => '0',
				'name' => $this->myString ('new name'),
				'comment' => 'new comment',
				'has_problems' => 'on',
			)
		);
		try
		{
			updateLocation();
			$this->fail ('the operation must fail');
		}
		catch (InvalidRequestArgException $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	public function circularParentProvider ()
	{
		return array
		(
			// a location's parent may not be one of its children
			array ('location', 'child_location_id'),
			array ('rackspace', 'child_location_id'),
			// nor the location itself
			array ('location', 'location_id'),
			array ('rackspace', 'location_id'),
		);
	}

	/**
	 * @group small
	 * @dataProvider circularParentProvider
	 */
	public function testUpdateLocationCircularParent ($pageno, $parent)
	{
		$before = $this->snapshot();
		$this->setRequest
		(
			$pageno,
			array
			(
				'location_id' => $this->location_id,
				'parent_id' => $this->$parent,
				'name' => $this->myString ('new name'),
				'comment' => 'new comment',
				'has_problems' => 'on',
				'num_attrs' => '0',
			)
		);
		try
		{
			updateLocation();
			$this->fail ('the operation must fail');
		}
		// an ordinary argument error, not an internal one
		catch (InvalidRequestArgException $e)
		{
			$this->assertEquals ('parent_id', $e->name);
		}
		$this->assertUnchanged ($before);
	}

	protected function updateLocationParent ($location_id, $parent_id)
	{
		$this->setRequest
		(
			'location',
			array
			(
				'location_id' => $location_id,
				'parent_id' => $parent_id,
				'name' => $this->myString ("name {$location_id}"),
				'comment' => '',
				'num_attrs' => '0',
			)
		);
		// every call is a request of its own
		$GLOBALS['entityCache'] = array();
		updateLocation();
	}

	protected function parentOf ($location_id)
	{
		$location = spotEntity ('location', $location_id, TRUE);
		return $location['parent_id'];
	}

	// Data from before the circular reference checks: the location is the
	// parent of its own parent. Removing the parent of either location
	// repairs it, assigning another parent works after that.
	/**
	 * @group small
	 */
	public function testRepairCircularLocations ()
	{
		$new_location_id = commitAddObject ($this->myString ('new location'), NULL, 1562, NULL);
		foreach (array ($this->location_id, $this->child_location_id) as $location_id)
		{
			usePreparedInsertBlade
			(
				'EntityLink',
				array
				(
					'parent_entity_type' => 'location',
					'parent_entity_id' => $this->child_location_id,
					'child_entity_type' => 'location',
					'child_entity_id' => $this->location_id,
				)
			);
			// the descendants of a location in the loop cannot be listed,
			// so a new parent cannot be checked and is refused
			$before = $this->snapshot();
			try
			{
				$this->updateLocationParent ($location_id, $new_location_id);
				$this->fail ('the operation must fail');
			}
			catch (RackTablesError $e)
			{
			}
			$this->assertEquals ($before, $this->snapshot());
			$this->updateLocationParent ($location_id, '0');
			$this->assertNull ($this->parentOf ($location_id));
			$this->updateLocationParent ($location_id, $new_location_id);
			$this->assertEquals ($new_location_id, $this->parentOf ($location_id));
			$this->assertFalse (isTransactionActive());
			// back to the initial tree
			commitUnlinkEntities ('location', $new_location_id, 'location', $location_id);
			commitUnlinkEntities ('location', $this->child_location_id, 'location', $this->location_id);
			if ($location_id == $this->child_location_id)
				commitLinkEntities ('location', $this->location_id, 'location', $this->child_location_id);
			$this->assertNull ($this->parentOf ($this->location_id));
			$this->assertEquals ($this->location_id, $this->parentOf ($this->child_location_id));
		}
	}

	// Data from before the EntityLink triggers: the location is its own parent.
	/**
	 * @group small
	 */
	public function testRepairSelfParentedLocation ()
	{
		global $dbxlink;
		$new_location_id = commitAddObject ($this->myString ('new location'), NULL, 1562, NULL);
		// the trigger refuses such a link, so go around it
		try
		{
			$result = $dbxlink->query ('SHOW CREATE TRIGGER `EntityLink-before-insert`');
			$row = $result->fetch (PDO::FETCH_NUM);
			unset ($result);
			$dbxlink->exec ('DROP TRIGGER `EntityLink-before-insert`');
		}
		catch (PDOException $e)
		{
			$this->markTestSkipped ('cannot replace the EntityLink trigger: ' . $e->getMessage());
		}
		try
		{
			usePreparedInsertBlade
			(
				'EntityLink',
				array
				(
					'parent_entity_type' => 'location',
					'parent_entity_id' => $this->location_id,
					'child_entity_type' => 'location',
					'child_entity_id' => $this->location_id,
				)
			);
		}
		finally
		{
			$dbxlink->exec ($row[2]);
		}
		$this->assertEquals ($this->location_id, $this->parentOf ($this->location_id));
		$before = $this->snapshot();
		try
		{
			$this->updateLocationParent ($this->location_id, $new_location_id);
			$this->fail ('the operation must fail');
		}
		catch (RackTablesError $e)
		{
		}
		$this->assertEquals ($before, $this->snapshot());
		$this->updateLocationParent ($this->location_id, '0');
		$this->assertNull ($this->parentOf ($this->location_id));
		$this->updateLocationParent ($this->location_id, $new_location_id);
		$this->assertEquals ($new_location_id, $this->parentOf ($this->location_id));
		$this->assertEquals ($this->location_id, $this->parentOf ($this->child_location_id));
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testUpdateRowFailingLate ()
	{
		$before = $this->snapshot();
		// num_attrs is missing
		$this->setRequest
		(
			'row',
			array
			(
				'row_id' => $this->row_id,
				'location_id' => $this->child_location_id,
				'name' => $this->myString ('new name'),
			)
		);
		try
		{
			updateRow();
			$this->fail ('the operation must fail');
		}
		catch (InvalidRequestArgException $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testUpdateRowRemoveLocation ()
	{
		$this->setRequest
		(
			'row',
			array
			(
				'row_id' => $this->row_id,
				'location_id' => '0',
				'name' => $this->myString ('new name'),
				'num_attrs' => '0',
			)
		);
		updateRow();
		$row = spotEntity ('row', $this->row_id, TRUE);
		$this->assertNull ($row['location_id']);
		$this->assertEquals ($this->myString ('new name'), $row['name']);
		$this->assertFalse (isTransactionActive());
	}

	protected function updateRackRequest ($args)
	{
		$this->setRequest
		(
			'rack',
			$args + array
			(
				'rack_id' => $this->rack_ids[0],
				'row_id' => $this->row_id,
				'name' => $this->myString ('new name'),
				'height' => '40',
				'asset_no' => '',
				'comment' => 'new comment',
				'taglist' => array(),
			)
		);
	}

	/**
	 * @group small
	 */
	public function testUpdateRackFailingLate ()
	{
		$other_row_id = commitAddObject ($this->myString ('other row'), NULL, 1561, NULL);
		$before = $this->snapshot();
		// num_attrs is missing
		$this->updateRackRequest (array ('row_id' => $other_row_id));
		try
		{
			updateRack();
			$this->fail ('the operation must fail');
		}
		catch (InvalidRequestArgException $e)
		{
		}
		$this->assertUnchanged ($before);
		commitDeleteObject ($other_row_id);
	}

	/**
	 * @group small
	 */
	public function testUpdateRackInvalidAttribute ()
	{
		$before = $this->snapshot();
		// the height is not one of the attributes this form may change
		$this->updateRackRequest (array ('num_attrs' => '1', '0_attr_id' => '27', '0_value' => '10'));
		try
		{
			updateRack();
			$this->fail ('the operation must fail');
		}
		catch (RackTablesError $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testUpdateRackSuccess ()
	{
		$this->updateRackRequest (array ('num_attrs' => '0'));
		updateRack();
		$rack = spotEntity ('rack', $this->rack_ids[0], TRUE);
		$this->assertEquals ($this->myString ('new name'), $rack['name']);
		$this->assertEquals (40, $rack['height']);
		$this->assertEquals ('new comment', $rack['comment']);
		$this->assertCount (0, $this->fetchRows ('SELECT tag_id FROM TagStorage WHERE entity_realm = "rack" AND entity_id = ?', array ($this->rack_ids[0])));
		$this->assertCount (0, $this->fetchRows ('SELECT rack_id FROM RackThumbnail WHERE rack_id = ?', array ($this->rack_ids[0])));
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testDeleteRackFailing ()
	{
		$before = $this->snapshot();
		$this->failDeleteOf ($this->rack_ids[0]);
		try
		{
			commitDeleteRack ($this->rack_ids[0]);
			$this->fail ('the operation must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testDeleteRowFailingOnRow ()
	{
		$before = $this->snapshot();
		$this->failDeleteOf ($this->row_id);
		try
		{
			commitDeleteRow ($this->row_id);
			$this->fail ('the operation must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testDeleteRowFailingOnSecondRack ()
	{
		$before = $this->snapshot();
		$this->failDeleteOf ($this->rack_ids[1]);
		try
		{
			commitDeleteRow ($this->row_id);
			$this->fail ('the operation must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testDeleteRowFailingInOuterTransaction ()
	{
		global $dbxlink;
		$before = $this->snapshot();
		$this->failDeleteOf ($this->rack_ids[1]);
		$dbxlink->beginTransaction();
		try
		{
			commitDeleteRow ($this->row_id);
			$this->fail ('the operation must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		// the caller handles the error and keeps control of its transaction
		$this->assertTrue (isTransactionActive());
		$dbxlink->commit();
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testDeleteRowSuccess ()
	{
		commitDeleteRow ($this->row_id);
		$ids = array_merge (array ($this->row_id), $this->rack_ids);
		$in = questionMarks (count ($ids));
		$this->assertCount (0, $this->fetchRows ("SELECT id FROM Object WHERE id IN ({$in})", $ids));
		$in = questionMarks (count ($this->rack_ids));
		$this->assertCount (0, $this->fetchRows ("SELECT id FROM FileLink WHERE entity_type = 'rack' AND entity_id IN ({$in})", $this->rack_ids));
		$this->assertCount (0, $this->fetchRows ("SELECT entity_id FROM TagStorage WHERE entity_realm = 'rack' AND entity_id IN ({$in})", $this->rack_ids));
		$this->assertCount (0, $this->fetchRows ("SELECT rack_id FROM RackSpace WHERE rack_id IN ({$in})", $this->rack_ids));
		$this->assertNothingStoredFor ('row', $this->row_id);
		$this->assertFalse (isTransactionActive());
	}

	protected function assertNothingStoredFor ($realm, $entity_id)
	{
		$this->assertCount
		(
			0,
			$this->fetchRows ('SELECT id FROM FileLink WHERE entity_type = ? AND entity_id = ?', array ($realm, $entity_id)),
			"file links of {$realm} {$entity_id}"
		);
		$this->assertCount
		(
			0,
			$this->fetchRows ('SELECT tag_id FROM TagStorage WHERE entity_realm = ? AND entity_id = ?', array ($realm, $entity_id)),
			"tags of {$realm} {$entity_id}"
		);
	}

	// Racks, rows and locations deleted by commitDeleteObject() directly must
	// not leave their file links and tags behind either.
	/**
	 * @group small
	 */
	public function testDeleteObjectReleasesRealm ()
	{
		$object_id = commitAddObject ($this->myString ('object'), NULL, 4, NULL, array (self::$tag_id));
		usePreparedInsertBlade ('FileLink', array ('file_id' => self::$file_id, 'entity_type' => 'object', 'entity_id' => $object_id));
		usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $this->rack_ids[0]));
		$deleted = array
		(
			'object' => $object_id,
			'rack' => $this->rack_ids[0],
			'row' => $this->row_id,
			'location' => $this->child_location_id,
		);
		foreach ($deleted as $realm => $entity_id)
		{
			commitDeleteObject ($entity_id);
			$this->assertCount (0, $this->fetchRows ('SELECT id FROM Object WHERE id = ?', array ($entity_id)));
			$this->assertNothingStoredFor ($realm, $entity_id);
		}
		// the entities that were not deleted keep theirs
		$this->assertCount (1, $this->fetchRows ('SELECT id FROM FileLink WHERE entity_type = "rack" AND entity_id = ?', array ($this->rack_ids[1])));
		$this->assertCount (1, $this->fetchRows ('SELECT tag_id FROM TagStorage WHERE entity_realm = "location" AND entity_id = ?', array ($this->location_id)));
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testDeleteLocationFailing ()
	{
		$before = $this->snapshot();
		$this->failDeleteOf ($this->child_location_id);
		$this->setRequest ('location', array ('location_id' => $this->child_location_id));
		try
		{
			deleteLocation();
			$this->fail ('the operation must fail');
		}
		catch (RTDatabaseError $e)
		{
		}
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testDeleteLocationSuccess ()
	{
		$this->setRequest ('location', array ('location_id' => $this->child_location_id));
		deleteLocation();
		$this->assertCount (0, $this->fetchRows ('SELECT id FROM Object WHERE id = ?', array ($this->child_location_id)));
		$this->assertCount (0, $this->fetchRows ('SELECT id FROM FileLink WHERE entity_type = "location" AND entity_id = ?', array ($this->child_location_id)));
		$this->assertFalse (isTransactionActive());
	}

	protected function addRacksRequest ($names, $height)
	{
		global $log_messages;
		$this->setRequest
		(
			'row',
			array
			(
				'row_id' => $this->row_id,
				'mode' => 'many',
				'height2' => $height,
				'names' => implode ("\n", $names),
				'taglist' => array (self::$tag_id),
			)
		);
		setMessageBuffering (TRUE);
		$log_messages = array();
	}

	// the codes of the messages shown to the user
	protected function messageCodes ()
	{
		$ret = array();
		foreach ($GLOBALS['log_messages'] as $line)
			$ret[] = $line['c'];
		return $ret;
	}

	/**
	 * @group small
	 */
	public function testAddRacksWithFailingLine ()
	{
		// the second name is longer than the column
		$this->addRacksRequest (array ($this->myString ('new rack 1'), str_repeat ('x', 300), $this->myString ('new rack 2')), '42');
		addRack();
		// every line is reported and the others are added
		$this->assertEquals (array (0, 100, 0), $this->messageCodes());
		foreach (array (1 => 3, 2 => 4) as $n => $sort_order)
		{
			$rows = $this->fetchRows ('SELECT id FROM Object WHERE name = ?', array ($this->myString ("new rack {$n}")));
			$this->assertCount (1, $rows);
			$rack = spotEntity ('rack', $rows[0]['id']);
			$this->assertEquals ($this->row_id, $rack['row_id']);
			$this->assertEquals (42, $rack['height']);
			$this->assertEquals
			(
				array (array ('uint_value' => $sort_order)),
				$this->fetchRows ('SELECT uint_value FROM AttributeValue WHERE object_id = ? AND attr_id = 29', array ($rack['id']))
			);
		}
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testAddRacksFailingLate ()
	{
		$before = $this->snapshot();
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM Object');
		$count = $result->fetchColumn();
		unset ($result);
		// the height does not fit the column, which fails after the rack
		// object has been created
		$this->addRacksRequest (array ($this->myString ('new rack 1'), $this->myString ('new rack 2')), '99999999999');
		addRack();
		$this->assertEquals (array (100, 100), $this->messageCodes());
		$this->assertNoObjectNamed ($this->myString ('new rack 1'));
		$this->assertNoObjectNamed ($this->myString ('new rack 2'));
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM Object');
		$this->assertEquals ($count, $result->fetchColumn());
		unset ($result);
		$this->assertUnchanged ($before);
	}

	/**
	 * @group small
	 */
	public function testAddRacksLastLineFailingLate ()
	{
		global $dbxlink;
		// make the line with this name fail after the rack object is created
		$failing_name = $this->myString ('new rack 2');
		try
		{
			$dbxlink->exec
			(
				'CREATE TRIGGER `EntityChangeRollbackTest-fail` BEFORE INSERT ON `AttributeValue` FOR EACH ROW ' .
				'IF NEW.object_id = (SELECT id FROM Object WHERE name = ' . $dbxlink->quote ($failing_name) . ') THEN ' .
				"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF"
			);
		}
		catch (PDOException $e)
		{
			$this->markTestSkipped ('cannot create the trigger: ' . $e->getMessage());
		}
		// no rack is created
		$last_id = getLastCreatedId ('rack');
		$count = count (lastCreated());
		$this->addRacksRequest (array ($failing_name), '42');
		addRack();
		$this->assertEquals (array (100), $this->messageCodes());
		$this->assertNoObjectNamed ($failing_name);
		$this->assertEquals ($last_id, getLastCreatedId ('rack'));
		$this->assertCount ($count, lastCreated());
		// the rack of the first line is the last one created
		$this->addRacksRequest (array ($this->myString ('new rack 1'), $failing_name), '42');
		addRack();
		$this->assertEquals (array (0, 100), $this->messageCodes());
		$this->assertNoObjectNamed ($failing_name);
		$rows = $this->fetchRows ('SELECT id FROM Object WHERE name = ?', array ($this->myString ('new rack 1')));
		$this->assertCount (1, $rows);
		$this->assertEquals ($rows[0]['id'], getLastCreatedId ('rack'));
		$this->assertEquals (array (array ('realm' => 'rack', 'id' => $rows[0]['id'])), array_slice (lastCreated(), $count));
		$this->assertFalse (isTransactionActive());
	}
}
