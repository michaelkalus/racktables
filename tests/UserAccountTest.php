<?php

class UserAccountTest extends RTTestCase
{
	const PSWDHASH = 'f7563fd105b011264532ef2d405082015bed948e';
	const REALNAME = 'Test User Account';
	private $user_name;
	private $user_id;

	public function setUp () : void
	{
		$this->user_name = $this->myString ('testuser');
		$this->user_id = commitCreateUserAccount ($this->user_name, self::REALNAME, self::PSWDHASH);
	}

	public function tearDown () : void
	{
		$GLOBALS['dbxlink']->exec ('DROP TRIGGER IF EXISTS `UserAccountTest-fail`');
		commitDeleteUserAccount ($this->user_id);
	}

	protected function countRows ($query, $args)
	{
		$result = usePreparedSelectBlade ($query, $args);
		$ret = $result->fetchColumn();
		unset ($result);
		return $ret;
	}

	/**
	 * @group small
	 */
	public function testNormal ()
	{
		$this->assertArrayHasKey ($this->user_id, getAccountSearchResult ($this->user_name));
		$this->assertEquals ($this->user_id, getUserIDByUsername ($this->user_name));
		$this->assertNull (getUserIDByUsername ('x' . $this->user_name));
		$user = spotEntity ('user', $this->user_id);
		$this->assertEquals ($this->user_name, $user['user_name']);
		$this->assertEquals (self::PSWDHASH, $user['user_password_hash']);
		$this->assertEquals (self::REALNAME, $user['user_realname']);

		$this->user_name = 'x' . $this->user_name;
		commitUpdateUserAccount ($this->user_id, $this->user_name, 'x' . self::REALNAME, sha1 (self::PSWDHASH));

		$this->assertArrayHasKey ($this->user_id, getAccountSearchResult ($this->user_name));
		$this->assertEquals ($this->user_id, getUserIDByUsername ($this->user_name));
		$this->assertNull (getUserIDByUsername ('x' . $this->user_name));
		$user = spotEntity ('user', $this->user_id, TRUE);
		$this->assertEquals ($this->user_name, $user['user_name']);
		$this->assertEquals (sha1 (self::PSWDHASH), $user['user_password_hash']);
		$this->assertEquals ('x' . self::REALNAME, $user['user_realname']);
	}

	/**
	 * @group small
	 */
	public function testDuplicate ()
	{
		$this->expectException (RTDatabaseError::class);
		commitCreateUserAccount ($this->user_name, 'x' . self::REALNAME, sha1 (self::PSWDHASH));
	}

	/**
	 * @group small
	 */
	public function testInvalidPassword1 ()
	{
		$this->expectException (InvalidArgException::class);
		commitUpdateUserAccount ($this->user_id, $this->user_name, self::REALNAME, 'password');
	}

	/**
	 * @group small
	 */
	public function testInvalidPassword2 ()
	{
		$this->expectException (InvalidArgException::class);
		commitCreateUserAccount ($this->user_name, self::REALNAME, 'password');
	}

	/**
	 * @group small
	 */
	public function testDelete1 ()
	{
		$this->expectException (EntityNotFoundException::class);
		$user2_id = commitCreateUserAccount ($this->user_name . 'x', self::REALNAME, self::PSWDHASH);
		commitDeleteUserAccount ($user2_id);
		spotEntity ('user', $user2_id, TRUE);
	}

	/**
	 * @group small
	 */
	public function testDelete2 ()
	{
		$this->expectException (InvalidArgException::class);
		commitDeleteUserAccount (1);
	}

	/**
	 * @group small
	 */
	public function testDelete3 ()
	{
		$this->expectException (EntityNotFoundException::class);
		commitDeleteUserAccount (1000000);
	}

	/**
	 * @group small
	 */
	public function testDeleteReleasesFilesAndTags ()
	{
		usePreparedInsertBlade ('TagTree', array ('tag' => $this->myString ('tag')));
		$tag_id = lastInsertID();
		$file_id = commitAddFile ($this->myString ('file'), 'text/plain', 'test', '');
		$user2_id = commitCreateUserAccount ($this->user_name . 'x', self::REALNAME, self::PSWDHASH);
		usePreparedInsertBlade ('FileLink', array ('file_id' => $file_id, 'entity_type' => 'user', 'entity_id' => $user2_id));
		usePreparedInsertBlade ('TagStorage', array ('entity_realm' => 'user', 'entity_id' => $user2_id, 'tag_id' => $tag_id));
		commitDeleteUserAccount ($user2_id);
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM FileLink WHERE entity_type = "user" AND entity_id = ?', array ($user2_id));
		$links = $result->fetchColumn();
		unset ($result);
		$result = usePreparedSelectBlade ('SELECT COUNT(*) FROM TagStorage WHERE entity_realm = "user" AND entity_id = ?', array ($user2_id));
		$tags = $result->fetchColumn();
		unset ($result);
		commitDeleteFile ($file_id);
		usePreparedDeleteBlade ('TagTree', array ('id' => $tag_id));
		$this->assertEquals (0, $links);
		$this->assertEquals (0, $tags);
	}

	/**
	 * @group small
	 */
	public function testDeleteFailing ()
	{
		global $dbxlink;
		usePreparedInsertBlade ('TagTree', array ('tag' => $this->myString ('tag')));
		$tag_id = lastInsertID();
		$file_id = commitAddFile ($this->myString ('file'), 'text/plain', 'test', '');
		usePreparedInsertBlade ('FileLink', array ('file_id' => $file_id, 'entity_type' => 'user', 'entity_id' => $this->user_id));
		usePreparedInsertBlade ('TagStorage', array ('entity_realm' => 'user', 'entity_id' => $this->user_id, 'tag_id' => $tag_id));
		usePreparedInsertBlade ('UserConfig', array ('varname' => 'SHOW_LAST_TAB', 'varvalue' => 'yes', 'user' => $this->user_name));
		$counts = array
		(
			'SELECT COUNT(*) FROM FileLink WHERE entity_type = "user" AND entity_id = ?' => $this->user_id,
			'SELECT COUNT(*) FROM TagStorage WHERE entity_realm = "user" AND entity_id = ?' => $this->user_id,
			'SELECT COUNT(*) FROM UserConfig WHERE user = ?' => $this->user_name,
			'SELECT COUNT(*) FROM UserAccount WHERE user_id = ?' => $this->user_id,
		);
		try
		{
			// make the last statement fail
			try
			{
				$dbxlink->exec
				(
					'CREATE TRIGGER `UserAccountTest-fail` BEFORE DELETE ON `UserAccount` FOR EACH ROW ' .
					"IF OLD.user_id = {$this->user_id} THEN " .
					"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF"
				);
			}
			catch (PDOException $e)
			{
				$this->markTestSkipped ('cannot create the trigger: ' . $e->getMessage());
			}
			try
			{
				commitDeleteUserAccount ($this->user_id);
				$this->fail ('the operation must fail');
			}
			catch (PDOException $e)
			{
				$this->assertStringContainsString ('injected failure', $e->getMessage());
			}
			foreach ($counts as $query => $arg)
				$this->assertEquals (1, $this->countRows ($query, array ($arg)), $query);
			$this->assertFalse (isTransactionActive());
		}
		finally
		{
			$dbxlink->exec ('DROP TRIGGER IF EXISTS `UserAccountTest-fail`');
			releaseFiles ('user', $this->user_id);
			destroyTagsForEntity ('user', $this->user_id);
			commitDeleteFile ($file_id);
			usePreparedDeleteBlade ('TagTree', array ('id' => $tag_id));
		}
	}
}
