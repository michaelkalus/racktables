<?php

// Make sure renderDepot does not throw any exceptions
class RenderDepotTest extends RTTestCase
{
	protected $shelf_id = NULL;
	protected $modem_id = NULL;
	protected $rack_id = NULL;
	protected $server_id = NULL;

	public function setUp () : void
	{
		@session_start();
		// create a nameless shelf that contains a modem (re: ticket #1115)
		$this->shelf_id = commitAddObject (NULL, NULL, 3, NULL);
		$this->modem_id = commitAddObject ($this->myString ('modem'), NULL, 13, NULL);
		commitLinkEntities ('object', $this->shelf_id, 'object', $this->modem_id);
	}

	public function tearDown () : void
	{
		// remove sample data
		commitDeleteObject ($this->modem_id);
		commitDeleteObject ($this->shelf_id);
		if ($this->server_id !== NULL)
			commitDeleteObject ($this->server_id);
		if ($this->rack_id !== NULL)
		{
			usePreparedDeleteBlade ('RackSpace', array ('rack_id' => $this->rack_id));
			commitDeleteObject ($this->rack_id);
		}
	}

	/**
	 * @group small
	 */
	public function testRenderDepot ()
	{
		$this->assertNotEquals ('', getOutputOf ('renderDepot'));
	}

	/**
	 * @group small
	 */
	public function testRenderDepotRackWithoutRow ()
	{
		// an object mounted in a rack that is not linked to any row
		$rack_name = $this->myString ('rack');
		$this->rack_id = commitAddObject ($rack_name, NULL, 1560, NULL);
		$this->server_id = commitAddObject ($this->myString ('server'), NULL, 4, NULL);
		usePreparedInsertBlade
		(
			'RackSpace',
			array
			(
				'rack_id' => $this->rack_id,
				'unit_no' => 1,
				'atom' => 'front',
				'state' => 'T',
				'object_id' => $this->server_id,
			)
		);
		// forget the object list cached by a previous renderDepot() call
		global $entityCache;
		$entityCache = array();
		$output = getOutputOf ('renderDepot');
		$this->assertStringContainsString ($rack_name, $output);
	}
}
