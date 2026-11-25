<?php

class LDAPCacheTest extends RTTestCase
{
	private $username;

	public function setUp () : void
	{
		$this->username = $this->myString ('ldapcache');
	}

	public function tearDown () : void
	{
		deleteLDAPCacheRecord ($this->username);
		deleteLDAPCacheRecord (mb_substr ($this->longName(), 0, 64));
	}

	private function longName ()
	{
		return str_pad ($this->username, 65, 'x');
	}

	// acquireLDAPCache() inserts an empty row to lock, which holds no group
	// list to unserialize.
	/**
	 * @group small
	 */
	public function testPlaceholderRow ()
	{
		$row = acquireLDAPCache ($this->username);
		releaseLDAPCache();
		$this->assertSame (array(), $row['memberof']);
		$this->assertSame ('', $row['successful_hash']);
	}

	/**
	 * @group small
	 */
	public function testCachedRow ()
	{
		replaceLDAPCacheRecord ($this->username, sha1 ('secret'), 'Some Name', array ('$lgcn_a', '$lgcn_b'));
		$row = fetchLDAPCacheRow ($this->username);
		$this->assertSame (array ('$lgcn_a', '$lgcn_b'), $row['memberof']);
		$this->assertSame (sha1 ('secret'), $row['successful_hash']);
		$this->assertSame ('Some Name', $row['displayed_name']);
	}

	// A row that cannot be found after the insert (here because the name does
	// not fit the column) makes acquireLDAPCache() retry and then give up
	// without leaving a transaction open.
	/**
	 * @group small
	 */
	public function testAcquireRetry ()
	{
		try
		{
			acquireLDAPCache ($this->longName());
			$this->fail ('acquireLDAPCache() did not fail');
		}
		catch (RackTablesError $e)
		{
			$this->assertSame ('Unable to acquire lock on LDAPCache', $e->getMessage());
		}
		$this->assertFalse (isTransactionActive());
	}
}
