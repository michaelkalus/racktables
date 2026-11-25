<?php
require_once '../wwwroot/inc/solutions.php';

// A rack that is not linked to any row (issue #23) must be visible to the
// application, its pages and the pages of the objects mounted in it must
// render, and the administrator must be able to assign a row to it.
class RackWithoutRowTest extends RTTestCase
{
	protected $location_id, $row_id, $rack_in_row_id, $rack_id, $server_id;
	protected $saved_request, $saved_sic, $saved_auto_tags, $saved_server;

	public function setUp () : void
	{
		global $sic, $auto_tags;
		$this->saved_request = $_REQUEST;
		$this->saved_server = $_SERVER;
		$this->saved_sic = $sic;
		$this->saved_auto_tags = $auto_tags;
		// The default RackCode allows everything to the admin account.
		$auto_tags[] = array ('tag' => '$userid_1');

		$this->location_id = commitAddObject ($this->myString ('location'), NULL, 1562, NULL);
		$this->row_id = commitAddObject ($this->myString ('row'), NULL, 1561, NULL);
		commitLinkEntities ('location', $this->location_id, 'row', $this->row_id);
		$this->rack_in_row_id = $this->addRack ('rack in row', 1);
		commitLinkEntities ('row', $this->row_id, 'rack', $this->rack_in_row_id);
		// a rack that has lost its link to the row, with a stale sort order
		$this->rack_id = $this->addRack ('rack', 5);
		$this->server_id = commitAddObject ($this->myString ('server'), NULL, 4, NULL);
		usePreparedInsertBlade
		(
			'RackSpace',
			array ('rack_id' => $this->rack_id, 'unit_no' => 1, 'atom' => 'front', 'state' => 'T', 'object_id' => $this->server_id)
		);
		$this->forgetCache();
	}

	public function tearDown () : void
	{
		global $sic, $auto_tags, $log_messages;
		setMessageBuffering (FALSE);
		$log_messages = array();
		$_REQUEST = $this->saved_request;
		$_SERVER = $this->saved_server;
		$sic = $this->saved_sic;
		$auto_tags = $this->saved_auto_tags;
		commitDeleteObject ($this->server_id);
		foreach (array ($this->rack_id, $this->rack_in_row_id) as $rack_id)
		{
			usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $rack_id));
			commitDeleteObject ($rack_id);
		}
		commitDeleteObject ($this->row_id);
		commitDeleteObject ($this->location_id);
		$this->forgetCache();
	}

	protected function addRack ($name, $sort_order)
	{
		$rack_id = commitAddObject ($this->myString ($name), NULL, 1560, NULL);
		commitUpdateAttrValue ($rack_id, 27, 10);
		commitUpdateAttrValue ($rack_id, 29, $sort_order);
		return $rack_id;
	}

	protected function forgetCache ()
	{
		global $entityCache;
		$entityCache = array();
	}

	protected function runOp ($pageno_, $tabno_, $op_, $args, $handler)
	{
		global $pageno, $tabno, $op, $sic;
		$pageno = $pageno_;
		$tabno = $tabno_;
		$op = $op_;
		$_REQUEST = $sic = $args;
		setMessageBuffering (TRUE);
		return $handler();
	}

	protected function updateRackArgs ($rack_id, $row_id)
	{
		return array
		(
			'page' => 'rack',
			'tab' => 'edit',
			'rack_id' => (string) $rack_id,
			'row_id' => (string) $row_id,
			'name' => $this->myString ('repaired'),
			'height' => '10',
			'asset_no' => '',
			'comment' => 'updated',
			'num_attrs' => '0',
			'taglist' => array(),
		);
	}

	protected function rowLinks ($rack_id)
	{
		$result = usePreparedSelectBlade
		(
			"SELECT parent_entity_id FROM EntityLink WHERE parent_entity_type = 'row' AND child_entity_type = 'rack' AND child_entity_id = ?",
			array ($rack_id)
		);
		return $result->fetchAll (PDO::FETCH_COLUMN, 0);
	}

	/**
	 * @group small
	 */
	public function testSpotAndList ()
	{
		$rack = spotEntity ('rack', $this->rack_id);
		$this->assertNull ($rack['row_id']);
		$this->assertNull ($rack['row_name']);
		$this->assertNull ($rack['location_id']);
		$this->assertEquals (10, $rack['height']);
		$this->assertArrayHasKey ($this->rack_id, listCells ('rack'));
		$this->assertArrayNotHasKey ($this->rack_id, listCells ('rack', $this->row_id));
		$this->assertArrayHasKey ($this->rack_id, getRackSearchResult ($this->myString ('rack')));
		// the neighbours and the proximity of such a rack are the rack alone
		$this->assertEquals (array ('prev' => NULL, 'next' => NULL), getRackNeighbors (NULL, $this->rack_id));
		$this->assertEquals (array ($this->rack_id), getProximateRacks ($this->rack_id, 2));
	}

	/**
	 * @group small
	 */
	public function testRackPages ()
	{
		global $pageno, $tabno, $sic;
		$pageno = 'rack';
		$tabno = 'default';
		$_REQUEST = $sic = array ('page' => 'rack', 'tab' => 'default', 'rack_id' => (string) $this->rack_id);
		$output = getOutputOf ('renderRackPage', $this->rack_id);
		$this->assertStringContainsString ('<h2>(no row) :</h2>', $output);
		$this->assertStringContainsString ("Rack row:</th><td class=tdleft>(no row)</td>", $output);
		$this->assertStringContainsString ($this->myString ('rack'), $output);
		$tabno = 'edit';
		$output = getOutputOf ('renderEditRackForm', $this->rack_id);
		$this->assertStringContainsString ("<option value='' selected>(no row)</option>", $output);
		$this->assertStringContainsString ("<option value='{$this->row_id}'>", $output);
		$tabno = 'design';
		$this->assertStringContainsString ('(no row)', getOutputOf ('renderRackDesign', $this->rack_id));
		$this->assertStringStartsWith ("\x89PNG", getOutputOf ('printRackThumbImage', $this->rack_id));
	}

	/**
	 * @group small
	 */
	public function testObjectPages ()
	{
		global $pageno, $tabno;
		$pageno = 'object';
		$tabno = 'default';
		$output = getOutputOf ('renderObject', $this->server_id);
		$this->assertStringContainsString ('<h2>(no row) :</h2>', $output);
		$tabno = 'rackspace';
		$_SERVER['REQUEST_URI'] = "/index.php?page=object&tab=rackspace&object_id={$this->server_id}";
		$output = getOutputOf ('renderRackSpaceForObject', $this->server_id);
		$this->assertStringContainsString ("<optgroup label='(no row)'>", $output);
		$this->assertStringContainsString ('(no row) : ', $output);
	}

	/**
	 * @group small
	 */
	public function testRackspacePage ()
	{
		global $pageno, $tabno;
		$pageno = 'rackspace';
		$tabno = 'default';
		$output = getOutputOf ('renderRackspace');
		$this->assertStringContainsString ('<th class=tdleft>(no row)</th>', $output);
		$this->assertStringContainsString ("rack_id={$this->rack_id}", $output);
	}

	/**
	 * showPathAndSearch() declares a nested function, so it can be called
	 * only once per process.
	 *
	 * @group small
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRackBreadcrumbs ()
	{
		$output = $this->renderBreadcrumbs ('rack', array ('rack_id' => $this->rack_id));
		$this->assertStringContainsString ("<a href='index.php?page=rackspace&tab=default'>(no row)</a>", $output);
		$this->assertStringNotContainsString ('row_id', $output);
	}

	/**
	 * @group small
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testObjectBreadcrumbs ()
	{
		$output = $this->renderBreadcrumbs ('object', array ('object_id' => $this->server_id));
		$this->assertStringContainsString (' : (no row) : ', $output);
		$this->assertStringNotContainsString ('row_id', $output);
	}

	protected function renderBreadcrumbs ($pageno_, $args)
	{
		global $pageno, $tabno, $sic;
		$pageno = $pageno_;
		$tabno = 'default';
		$_REQUEST = $sic = array_merge (array ('page' => $pageno_, 'tab' => 'default'), $args);
		return getOutputOf ('showPathAndSearch', $pageno_, 'default');
	}

	/**
	 * @group small
	 */
	public function testRepairThroughEditForm ()
	{
		// the row has a gap where the rack used to be
		commitUpdateAttrValue ($this->rack_in_row_id, 29, 3);
		$this->runOp ('rack', 'edit', 'updateRack', $this->updateRackArgs ($this->rack_id, $this->row_id), 'updateRack');
		$this->forgetCache();
		$this->assertEquals (array ($this->row_id), $this->rowLinks ($this->rack_id));
		$rack = spotEntity ('rack', $this->rack_id);
		$this->assertEquals ($this->row_id, $rack['row_id']);
		$this->assertEquals ($this->location_id, $rack['location_id']);
		$this->assertEquals ($this->myString ('repaired'), $rack['name']);
		$this->assertEquals ('updated', $rack['comment']);
		// placed at the end of the row, which is numbered without gaps
		$this->assertEquals (array ($this->rack_in_row_id, $this->rack_id), selectRackOrder ($this->row_id));
		$result = usePreparedSelectBlade
		(
			'SELECT object_id, uint_value FROM AttributeValue WHERE object_id IN (?, ?) AND attr_id = 29 ORDER BY uint_value',
			array ($this->rack_in_row_id, $this->rack_id)
		);
		$this->assertEquals (array ($this->rack_in_row_id => 1, $this->rack_id => 2), $result->fetchAll (PDO::FETCH_KEY_PAIR));
		unset ($result);
		$output = getOutputOf ('renderRackPage', $this->rack_id);
		$this->assertStringNotContainsString ('(no row)', $output);
		$this->assertStringContainsString ("row_id={$this->row_id}", $output);
		$this->assertFalse (isTransactionActive());
	}

	/**
	 * @group small
	 */
	public function testSaveWithoutRow ()
	{
		$this->runOp ('rack', 'edit', 'updateRack', $this->updateRackArgs ($this->rack_id, ''), 'updateRack');
		$this->forgetCache();
		$this->assertEquals (array(), $this->rowLinks ($this->rack_id));
		$rack = spotEntity ('rack', $this->rack_id);
		$this->assertNull ($rack['row_id']);
		$this->assertEquals ('updated', $rack['comment']);
	}

	/**
	 * @group small
	 */
	public function testRackInRowNeedsRow ()
	{
		try
		{
			$this->runOp ('rack', 'edit', 'updateRack', $this->updateRackArgs ($this->rack_in_row_id, ''), 'updateRack');
			$this->fail ('a rack in a row must not lose its row');
		}
		catch (InvalidRequestArgException $e)
		{
		}
		$this->assertEquals (array ($this->row_id), $this->rowLinks ($this->rack_in_row_id));
	}

	/**
	 * @group small
	 */
	public function testDeleteRack ()
	{
		usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $this->rack_id));
		$this->runOp ('rack', 'edit', 'deleteRack', array ('page' => 'rack', 'tab' => 'edit', 'rack_id' => (string) $this->rack_id), 'deleteRack');
		$this->forgetCache();
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM Object WHERE id = ?', array ($this->rack_id));
		$this->assertEquals (0, $result->fetchColumn());
		unset ($result);
		// the racks of the row keep their order
		$this->assertEquals (array ($this->rack_in_row_id), selectRackOrder ($this->row_id));
		$this->assertFalse (isTransactionActive());
	}
}
