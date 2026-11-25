<?php

require_once '../wwwroot/inc/popup.php';

// The port link operations of the object ports tab must only act on the ports
// of the object the permissions have been checked for.
class ObjectPortLinkTest extends RTTestCase
{
	protected static $objecta_id, $objectb_id, $objectc_id;
	protected static $porta1_id, $porta2_id, $portb1_id, $portb2_id, $portc1_id;
	protected $saved_rackcode;

	public static function setUpBeforeClass () : void
	{
		// add sample data, servers do not get AutoPorts in the way
		self::$objecta_id = commitAddObject (self::myStringStatic ('object a', __CLASS__), NULL, 4, NULL);
		self::$objectb_id = commitAddObject (self::myStringStatic ('object b', __CLASS__), NULL, 4, NULL);
		self::$objectc_id = commitAddObject (self::myStringStatic ('object c', __CLASS__), NULL, 4, NULL);
		self::$porta1_id = commitAddPort (self::$objecta_id, 'a1', '1-24', '', '');
		self::$porta2_id = commitAddPort (self::$objecta_id, 'a2', '1-24', '', '');
		self::$portb1_id = commitAddPort (self::$objectb_id, 'b1', '1-24', '', '');
		self::$portb2_id = commitAddPort (self::$objectb_id, 'b2', '1-24', '', '');
		self::$portc1_id = commitAddPort (self::$objectc_id, 'c1', '1-24', '', '');
	}

	public static function tearDownAfterClass () : void
	{
		// remove sample data (ports, links and port log go with the objects)
		commitDeleteObject (self::$objecta_id);
		commitDeleteObject (self::$objectb_id);
		commitDeleteObject (self::$objectc_id);
	}

	public function setUp () : void
	{
		global $rackCode;
		$this->saved_rackcode = $rackCode;
	}

	public function tearDown () : void
	{
		global $sic, $rackCode, $pageno, $tabno, $auto_tags, $user_given_tags, $target_given_tags, $log_messages;
		$rackCode = $this->saved_rackcode;
		foreach (array ('object_id', 'port_id', 'name', 'port_type_id', 'label', 'l2address', 'reservation_comment', 'cable', 'port', 'remote_port', 'do_link') as $argname)
			unset ($sic[$argname], $_REQUEST[$argname]);
		$pageno = 'index';
		$tabno = 'default';
		$auto_tags = $user_given_tags = $target_given_tags = array();
		fixContext();
		$log_messages = array();
		$ids = $this->allPortIDs();
		usePreparedExecuteBlade ('DELETE FROM Link WHERE porta IN (?,?,?,?,?) OR portb IN (?,?,?,?,?)', array_merge ($ids, $ids));
		usePreparedExecuteBlade ('DELETE FROM PortLog WHERE port_id IN (?,?,?,?,?)', $ids);
		usePreparedExecuteBlade ('UPDATE Port SET reservation_comment = NULL WHERE id IN (?,?,?,?,?)', $ids);
	}

	protected function allPortIDs ()
	{
		return array (self::$porta1_id, self::$porta2_id, self::$portb1_id, self::$portb2_id, self::$portc1_id);
	}

	protected function setRequest ($args)
	{
		global $sic;
		foreach ($args as $argname => $argvalue)
			$sic[$argname] = $_REQUEST[$argname] = $argvalue;
	}

	// run the ophandler the way index.php?module=redirect does
	protected function runOp ($opname, $object_id, $args)
	{
		global $ophandler, $pageno, $tabno, $op;
		$pageno = 'object';
		$tabno = 'ports';
		$op = $opname;
		$this->setRequest (array ('object_id' => $object_id) + $args);
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function runOpRefused ($opname, $object_id, $args)
	{
		try
		{
			$this->runOp ($opname, $object_id, $args);
			return FALSE;
		}
		catch (InvalidRequestArgException $e)
		{
			return TRUE;
		}
	}

	protected function linkCable ($port_id)
	{
		$result = usePreparedSelectBlade ('SELECT cable FROM Link WHERE porta = ? OR portb = ?', array ($port_id, $port_id));
		return $result->fetchColumn ();
	}

	protected function isLinked ($porta, $portb)
	{
		$result = usePreparedSelectBlade
		(
			'SELECT COUNT(*) FROM Link WHERE (porta = ? AND portb = ?) OR (porta = ? AND portb = ?)',
			array ($porta, $portb, $portb, $porta)
		);
		return $result->fetchColumn () == 1;
	}

	protected function portLogCount ($port_id)
	{
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM PortLog WHERE port_id = ?', array ($port_id));
		return $result->fetchColumn ();
	}

	/**
	 * @group small
	 */
	public function testUnlinkOwnPort ()
	{
		linkPorts (self::$porta1_id, self::$portb1_id);
		$this->runOp ('unlinkPort', self::$objecta_id, array ('port_id' => self::$porta1_id));
		$this->assertFalse ($this->isLinked (self::$porta1_id, self::$portb1_id));
	}

	/**
	 * @group small
	 */
	public function testUnlinkForeignPortRefused ()
	{
		linkPorts (self::$portb1_id, self::$portc1_id);
		$refused = $this->runOpRefused ('unlinkPort', self::$objecta_id, array ('port_id' => self::$portb1_id));
		$this->assertTrue ($this->isLinked (self::$portb1_id, self::$portc1_id), 'the link must not be deleted');
		$this->assertTrue ($refused, 'the request must be refused');
	}

	/**
	 * @group small
	 */
	public function testUnlinkMissingPortRefused ()
	{
		$this->expectException (InvalidRequestArgException::class);
		$this->runOp ('unlinkPort', self::$objecta_id, array ('port_id' => max ($this->allPortIDs()) + 1000));
	}

	protected function editPortArgs ($port_id, $name, $reservation_comment, $cable = NULL)
	{
		$ret = array
		(
			'port_id' => $port_id,
			'name' => $name,
			'port_type_id' => '1-24',
			'label' => '',
			'l2address' => '',
			'reservation_comment' => $reservation_comment,
		);
		if ($cable !== NULL)
			$ret['cable'] = $cable;
		return $ret;
	}

	/**
	 * @group small
	 */
	public function testEditOwnPortCable ()
	{
		linkPorts (self::$porta1_id, self::$portb1_id, 'old cable');
		$this->runOp ('editPort', self::$objecta_id, $this->editPortArgs (self::$porta1_id, 'a1', '', 'new cable'));
		$this->assertEquals ('new cable', $this->linkCable (self::$porta1_id));
	}

	/**
	 * @group small
	 */
	public function testEditOwnPortComment ()
	{
		$this->runOp ('editPort', self::$objecta_id, $this->editPortArgs (self::$porta2_id, 'a2', 'reserved'));
		$this->assertEquals ('reserved', getPortReservationComment (self::$porta2_id));
		$this->assertEquals (1, $this->portLogCount (self::$porta2_id));
	}

	/**
	 * @group small
	 */
	public function testEditForeignPortCableRefused ()
	{
		linkPorts (self::$portb1_id, self::$portc1_id, 'old cable');
		$refused = $this->runOpRefused ('editPort', self::$objecta_id, $this->editPortArgs (self::$portb1_id, 'b1', '', 'new cable'));
		$this->assertEquals ('old cable', $this->linkCable (self::$portb1_id), 'the cable ID must not change');
		$this->assertTrue ($refused, 'the request must be refused');
	}

	/**
	 * @group small
	 */
	public function testEditForeignPortCommentRefused ()
	{
		$refused = $this->runOpRefused ('editPort', self::$objecta_id, $this->editPortArgs (self::$portb2_id, 'b2', 'reserved'));
		$this->assertNull (getPortReservationComment (self::$portb2_id));
		$this->assertEquals (0, $this->portLogCount (self::$portb2_id), 'no port log entry must be written');
		$this->assertTrue ($refused, 'the request must be refused');
	}

	// Run the port link popup the way index.php?module=popup does, with the
	// given RackCode and a user, who has no tags of its own.
	protected function runPopupLink ($rackcode_text, $port_id, $remote_port_id, $page_object_id = NULL)
	{
		global $rackCode, $pageno, $tabno, $op;
		$op = NULL; // the popup has no operation
		$parsed = getRackCode ($rackcode_text);
		$this->assertEquals ('ACK', $parsed['result']);
		$rackCode = $parsed['load'];
		if ($page_object_id === NULL)
		{
			// the forms of the popup do not carry the page context
			$pageno = 'index';
			$tabno = 'default';
		}
		else
		{
			$pageno = 'object';
			$tabno = 'ports';
			$this->setRequest (array ('object_id' => $page_object_id));
		}
		fixContext();
		assertPermission();
		$this->setRequest (array ('port' => $port_id, 'remote_port' => $remote_port_id, 'cable' => '', 'do_link' => 'Link'));
		ob_start();
		try
		{
			renderPopupPortSelector();
		}
		catch (Exception $e)
		{
			ob_end_clean();
			throw $e;
		}
		ob_end_clean();
	}

	protected function editOneObjectRackCode ($object_id)
	{
		return "allow {\$id_{$object_id}}\nallow not {\$any_op}\n";
	}

	protected function assertPopupLinkRefused ($rackcode_text, $port_id, $remote_port_id, $page_object_id = NULL)
	{
		try
		{
			$this->runPopupLink ($rackcode_text, $port_id, $remote_port_id, $page_object_id);
			$refused = FALSE;
		}
		catch (RTPermissionDenied $e)
		{
			$refused = TRUE;
		}
		catch (InvalidRequestArgException $e)
		{
			$refused = TRUE;
		}
		$this->assertFalse ($this->isLinked ($port_id, $remote_port_id), 'the ports must not be linked');
		$this->assertTrue ($refused, 'the request must be refused');
	}

	/**
	 * @group small
	 */
	public function testPopupLinkOwnPort ()
	{
		$this->runPopupLink ($this->editOneObjectRackCode (self::$objecta_id), self::$porta1_id, self::$portb1_id);
		$this->assertTrue ($this->isLinked (self::$porta1_id, self::$portb1_id));
	}

	/**
	 * @group small
	 */
	public function testPopupLinkOwnPortWithPageContext ()
	{
		$this->runPopupLink ($this->editOneObjectRackCode (self::$objecta_id), self::$porta1_id, self::$portb1_id, self::$objecta_id);
		$this->assertTrue ($this->isLinked (self::$porta1_id, self::$portb1_id));
	}

	/**
	 * @group small
	 */
	public function testPopupLinkForeignPortsRefused ()
	{
		$this->assertPopupLinkRefused ($this->editOneObjectRackCode (self::$objecta_id), self::$portb1_id, self::$portc1_id);
	}

	/**
	 * @group small
	 */
	public function testPopupLinkForeignPortsInOwnPageContextRefused ()
	{
		$this->assertPopupLinkRefused ($this->editOneObjectRackCode (self::$objecta_id), self::$portb1_id, self::$portc1_id, self::$objecta_id);
	}

	/**
	 * @group small
	 */
	public function testPopupLinkReadOnlyRefused ()
	{
		$this->assertPopupLinkRefused ("allow not {\$any_op}\n", self::$porta1_id, self::$portb1_id);
	}

	/**
	 * @group small
	 */
	public function testPopupLinkMissingPortRefused ()
	{
		$this->expectException (InvalidRequestArgException::class);
		$this->runPopupLink ($this->editOneObjectRackCode (self::$objecta_id), max ($this->allPortIDs()) + 1000, self::$portb1_id);
	}
}
