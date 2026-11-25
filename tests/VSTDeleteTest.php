<?php

// Deleting a VLAN switch template must not leave its tags behind.
class VSTDeleteTest extends RTTestCase
{
	protected $tag_id, $vst_id;
	protected $saved_request, $saved_sic, $saved_pageno, $saved_tabno, $saved_op;

	public function setUp () : void
	{
		global $sic, $pageno, $tabno, $op, $taglist;
		$this->saved_request = $_REQUEST;
		$this->saved_sic = $sic;
		$this->saved_pageno = $pageno;
		$this->saved_tabno = $tabno;
		$this->saved_op = $op;
		usePreparedInsertBlade ('TagTree', array ('tag' => $this->myString ('tag')));
		$this->tag_id = lastInsertID();
		$taglist = addTraceToNodes (getTagList());
		usePreparedInsertBlade
		(
			'VLANSwitchTemplate',
			array ('description' => $this->myString ('vst'), 'mutex_rev' => 0, 'saved_by' => '')
		);
		$this->vst_id = lastInsertID();
		usePreparedInsertBlade ('TagStorage', array ('entity_realm' => 'vst', 'entity_id' => $this->vst_id, 'tag_id' => $this->tag_id));
		setMessageBuffering (TRUE);
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $tabno, $op, $log_messages;
		$_REQUEST = $this->saved_request;
		$sic = $this->saved_sic;
		$pageno = $this->saved_pageno;
		$tabno = $this->saved_tabno;
		$op = $this->saved_op;
		setMessageBuffering (FALSE);
		$log_messages = array();
		$GLOBALS['dbxlink']->exec ('DROP TRIGGER IF EXISTS `VSTDeleteTest-fail`');
		usePreparedDeleteBlade ('TagStorage', array ('entity_realm' => 'vst', 'entity_id' => $this->vst_id));
		usePreparedDeleteBlade ('VLANSwitchTemplate', array ('id' => $this->vst_id));
		usePreparedDeleteBlade ('TagTree', array ('id' => $this->tag_id));
	}

	protected function countRows ($query, $args)
	{
		$result = usePreparedSelectBlade ($query, $args);
		$ret = $result->fetchColumn();
		unset ($result);
		return $ret;
	}

	// run the ophandler of the "Manage templates" tab
	protected function runDelete ()
	{
		global $ophandler, $sic, $pageno, $tabno, $op;
		$pageno = '8021q';
		$tabno = 'vstlist';
		$op = 'del';
		$_REQUEST = $sic = array ('vst_id' => $this->vst_id);
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	/**
	 * @group small
	 */
	public function testDeleteVST ()
	{
		$this->runDelete();
		$this->assertEquals (0, $this->countRows ('SELECT COUNT(*) FROM VLANSwitchTemplate WHERE id = ?', array ($this->vst_id)));
		$this->assertEquals (0, $this->countRows ('SELECT COUNT(*) FROM TagStorage WHERE entity_realm = "vst" AND entity_id = ?', array ($this->vst_id)));
	}

	/**
	 * @group small
	 */
	public function testDeleteVSTFailing ()
	{
		global $dbxlink;
		// make the deletion of the tags fail after the template is gone
		try
		{
			$dbxlink->exec
			(
				'CREATE TRIGGER `VSTDeleteTest-fail` BEFORE DELETE ON `TagStorage` FOR EACH ROW ' .
				"IF OLD.entity_realm = 'vst' AND OLD.entity_id = {$this->vst_id} THEN " .
				"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF"
			);
		}
		catch (PDOException $e)
		{
			$this->markTestSkipped ('cannot create the trigger: ' . $e->getMessage());
		}
		try
		{
			$this->runDelete();
			$this->fail ('the operation must fail');
		}
		catch (PDOException $e)
		{
			$this->assertStringContainsString ('injected failure', $e->getMessage());
		}
		$this->assertEquals (1, $this->countRows ('SELECT COUNT(*) FROM VLANSwitchTemplate WHERE id = ?', array ($this->vst_id)));
		$this->assertEquals (1, $this->countRows ('SELECT COUNT(*) FROM TagStorage WHERE entity_realm = "vst" AND entity_id = ?', array ($this->vst_id)));
		$this->assertFalse (isTransactionActive());
	}
}
