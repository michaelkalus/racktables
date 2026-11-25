<?php

// The edit tab of an IPv4 virtual service must store the tags of that
// virtual service and must not change the tags of the VS group that has
// the same numeric id.
class VServiceTagsTest extends RTTestCase
{
	protected static $vs_id;
	protected static $tag_old_id, $tag_new_id;
	protected $request_args = array();

	public static function setUpBeforeClass () : void
	{
		global $taglist;
		usePreparedInsertBlade ('TagTree', array ('tag' => self::myStringStatic ('tag old', __CLASS__)));
		self::$tag_old_id = lastInsertID();
		usePreparedInsertBlade ('TagTree', array ('tag' => self::myStringStatic ('tag new', __CLASS__)));
		self::$tag_new_id = lastInsertID();
		$taglist = addTraceToNodes (getTagList());

		// an IPv4 virtual service and a VS group with the same id
		$result = usePreparedSelectBlade ('SELECT GREATEST((SELECT COALESCE(MAX(id), 0) FROM IPv4VS), (SELECT COALESCE(MAX(id), 0) FROM VS)) + 1');
		self::$vs_id = $result->fetchColumn();
		unset ($result);
		usePreparedInsertBlade ('IPv4VS', array
		(
			'id' => self::$vs_id,
			'vip' => ip_parse ('192.0.2.20'),
			'vport' => 80,
			'proto' => 'TCP',
			'name' => self::myStringStatic ('vs', __CLASS__),
		));
		usePreparedInsertBlade ('VS', array ('id' => self::$vs_id, 'name' => self::myStringStatic ('vsg', __CLASS__)));
	}

	public static function tearDownAfterClass () : void
	{
		commitDeleteVS (self::$vs_id);
		commitDeleteVSG (self::$vs_id);
		usePreparedDeleteBlade ('TagTree', array ('id' => array (self::$tag_old_id, self::$tag_new_id)));
	}

	public function setUp () : void
	{
		destroyTagsForEntity ('ipv4vs', self::$vs_id);
		destroyTagsForEntity ('ipvs', self::$vs_id);
		addTagForEntity ('ipvs', self::$vs_id, self::$tag_old_id);
	}

	public function tearDown () : void
	{
		global $sic;
		foreach ($this->request_args as $argname)
			unset ($sic[$argname], $_REQUEST[$argname]);
		$this->request_args = array();
	}

	// run the ophandler the way index.php?module=redirect does
	protected function runOp ($pageno_, $tabno_, $op_, $args)
	{
		global $ophandler, $pageno, $tabno, $op, $sic;
		$pageno = $pageno_;
		$tabno = $tabno_;
		$op = $op_;
		foreach ($args as $argname => $value)
		{
			$sic[$argname] = $_REQUEST[$argname] = $value;
			$this->request_args[] = $argname;
		}
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function getTagIDs ($realm)
	{
		$result = usePreparedSelectBlade
		(
			'SELECT tag_id FROM TagStorage WHERE entity_realm = ? AND entity_id = ? ORDER BY tag_id',
			array ($realm, self::$vs_id)
		);
		return $result->fetchAll (PDO::FETCH_COLUMN, 0);
	}

	/**
	 * @group small
	 */
	public function testUpdateVServiceTags ()
	{
		$this->runOp ('ipv4vs', 'edit', 'updIPv4VS', array
		(
			'vs_id' => self::$vs_id,
			'vip' => '192.0.2.20',
			'vport' => '80',
			'proto' => 'TCP',
			'name' => self::myStringStatic ('vs', __CLASS__),
			'vsconfig' => '',
			'rsconfig' => '',
			'taglist' => array (self::$tag_new_id),
		));
		$this->assertEquals (array (self::$tag_old_id), $this->getTagIDs ('ipvs'), 'the VS group must keep its tags');
		$this->assertEquals (array (self::$tag_new_id), $this->getTagIDs ('ipv4vs'), 'the virtual service must get the new tags');

		// the stored tag is loaded with the entity and visible to RackCode
		$vsinfo = spotEntity ('ipv4vs', self::$vs_id, TRUE);
		$this->assertEquals (array (self::$tag_new_id), array_keys ($vsinfo['etags']));
		$expr = compileExpression ('{' . self::myStringStatic ('tag new', __CLASS__) . '}');
		$this->assertNotNull ($expr);
		$this->assertCount (1, filterCellList (array ($vsinfo), $expr));
	}
}
