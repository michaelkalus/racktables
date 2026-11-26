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
		$hash = makeLDAPCacheHash ('secret');
		replaceLDAPCacheRecord ($this->username, $hash, 'Some Name', array ('$lgcn_a', '$lgcn_b'));
		$row = fetchLDAPCacheRow ($this->username);
		$this->assertSame (array ('$lgcn_a', '$lgcn_b'), $row['memberof']);
		// the whole value fits the column
		$this->assertSame ($hash, $row['successful_hash']);
		$this->assertSame ('Some Name', $row['displayed_name']);
	}

	// The cache stores a salted password_hash() result, not a plain digest.
	/**
	 * @group small
	 */
	public function testHashIsSaltedAndSlow ()
	{
		$a = makeLDAPCacheHash ('secret');
		$b = makeLDAPCacheHash ('secret');
		$this->assertNotSame ($a, $b);
		$this->assertTrue (isModernPasswordHash ($a));
		$this->assertFalse (isLegacyPasswordHash ($a));
		$this->assertLessThanOrEqual (255, strlen ($a));
		$this->assertTrue (LDAPCachePasswordMatches ($this->username, $a, 'secret'));
		$this->assertTrue (LDAPCachePasswordMatches ($this->username, $b, 'secret'));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $a, 'Secret'));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $a, ''));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $a, NULL));
	}

	// A directory password is not limited to what bcrypt reads: the bytes after
	// the first 72 and after a NUL byte count as well.
	/**
	 * @group small
	 */
	public function testLongAndBinaryPasswords ()
	{
		$long = str_repeat ('x', 72);
		$hash = makeLDAPCacheHash ($long . 'a');
		$this->assertTrue (LDAPCachePasswordMatches ($this->username, $hash, $long . 'a'));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $hash, $long . 'b'));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $hash, $long));
		$hash = makeLDAPCacheHash ("abc\0def");
		$this->assertTrue (LDAPCachePasswordMatches ($this->username, $hash, "abc\0def"));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $hash, "abc\0xyz"));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $hash, 'abc'));
		$hash = makeLDAPCacheHash ('');
		$this->assertTrue (LDAPCachePasswordMatches ($this->username, $hash, ''));
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $hash, 'x'));
	}

	// Neither the empty value of a row that only serves as a lock nor a digest
	// written by an earlier release matches any password.
	/**
	 * @group small
	 * @dataProvider providerUnusableHashes
	 */
	public function testUnusableStoredValue ($stored, $password)
	{
		$this->assertFalse (LDAPCachePasswordMatches ($this->username, $stored, $password));
	}

	public function providerUnusableHashes ()
	{
		return array
		(
			array ('', ''),
			array ('', 'secret'),
			array (NULL, 'secret'),
			array (sha1 ('secret'), 'secret'),
			array (sha1 ('secret'), sha1 ('secret')),
			array ('$unknown$abc', 'secret'),
			// a hash of the password itself, without the SHA-256 step
			array (password_hash ('secret', PASSWORD_DEFAULT), 'secret'),
		);
	}

	/**
	 * @group small
	 */
	public function testCacheRowValidity ()
	{
		global $LDAP_options;
		$saved = isset ($LDAP_options) ? $LDAP_options : NULL;
		$LDAP_options = array ('cache_refresh' => 300, 'cache_retry' => 15, 'cache_expiry' => 600);
		try
		{
			$row = acquireLDAPCache ($this->username);
			releaseLDAPCache();
			// the placeholder row is fresh, but holds no hash
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, '', TRUE));
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, 'secret', FALSE));

			replaceLDAPCacheRecord ($this->username, makeLDAPCacheHash ('secret'), 'Some Name', array());
			$row = fetchLDAPCacheRow ($this->username);
			$this->assertTrue (isLDAPCacheValid ($row, $this->username, 'secret', TRUE));
			$this->assertTrue (isLDAPCacheValid ($row, $this->username, 'secret', FALSE));
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, 'wrong', TRUE));
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, 'wrong', FALSE));
			$this->assertFalse (isLDAPCacheValid (FALSE, $this->username, 'secret', FALSE));

			// due for a refresh, still usable when the server does not answer
			$row['success_age'] = 400;
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, 'secret', TRUE));
			$this->assertTrue (isLDAPCacheValid ($row, $this->username, 'secret', FALSE));
			$row['retry_age'] = 10;
			$this->assertTrue (isLDAPCacheValid ($row, $this->username, 'secret', TRUE));
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, 'wrong', TRUE));
			// expired
			$row['success_age'] = 600;
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, 'secret', TRUE));
			$this->assertFalse (isLDAPCacheValid ($row, $this->username, 'secret', FALSE));
		}
		finally
		{
			$LDAP_options = $saved;
		}
	}

	// Only an unexpired row with another password survives a request that was
	// not authenticated.
	/**
	 * @group small
	 */
	public function testRowOfAnotherPassword ()
	{
		global $LDAP_options;
		$saved = isset ($LDAP_options) ? $LDAP_options : NULL;
		$LDAP_options = array ('cache_refresh' => 300, 'cache_retry' => 15, 'cache_expiry' => 600);
		try
		{
			// the row that only serves as a lock
			$row = acquireLDAPCache ($this->username);
			releaseLDAPCache();
			$this->assertFalse (isLDAPCacheRowOfAnotherPassword ($row, $this->username, 'wrong'));
			$this->assertFalse (isLDAPCacheRowOfAnotherPassword (FALSE, $this->username, 'wrong'));

			replaceLDAPCacheRecord ($this->username, makeLDAPCacheHash ('secret'), 'Some Name', array());
			$row = fetchLDAPCacheRow ($this->username);
			$this->assertTrue (isLDAPCacheRowOfAnotherPassword ($row, $this->username, 'wrong'));
			$this->assertTrue (isLDAPCacheRowOfAnotherPassword ($row, $this->username, ''));
			// the server has refused the cached password
			$this->assertFalse (isLDAPCacheRowOfAnotherPassword ($row, $this->username, 'secret'));
			// due for a refresh, not expired
			$row['success_age'] = 400;
			$this->assertTrue (isLDAPCacheRowOfAnotherPassword ($row, $this->username, 'wrong'));
			$row['success_age'] = 600;
			$this->assertFalse (isLDAPCacheRowOfAnotherPassword ($row, $this->username, 'wrong'));
			// a digest of an earlier release
			$row['success_age'] = 0;
			$row['successful_hash'] = sha1 ('secret');
			$this->assertFalse (isLDAPCacheRowOfAnotherPassword ($row, $this->username, 'wrong'));
		}
		finally
		{
			$LDAP_options = $saved;
		}
	}

	// The stored digest of the configuration follows every option but the
	// password of the search account.
	/**
	 * @group small
	 */
	public function testConfigHash ()
	{
		global $LDAP_options;
		$saved = isset ($LDAP_options) ? $LDAP_options : NULL;
		$base = array
		(
			'server' => 'ldap.example.com',
			'search_dn' => 'ou=people,dc=example,dc=com',
			'search_attr' => 'uid',
			'search_bind_rdn' => 'cn=reader,dc=example,dc=com',
			'cache_expiry' => 600,
		);
		try
		{
			$LDAP_options = $base;
			$without = getLDAPConfigHash();
			$this->assertSame (1, preg_match ('/^[0-9a-f]{64}$/D', $without));
			$this->assertSame ($base, $LDAP_options);

			$LDAP_options = $base + array ('search_bind_password' => 'secret');
			$this->assertSame ($without, getLDAPConfigHash());
			$this->assertSame ('secret', $LDAP_options['search_bind_password']);
			$LDAP_options['search_bind_password'] = 'another secret';
			$this->assertSame ($without, getLDAPConfigHash());
			$this->assertNotSame (sha1 (serialize ($LDAP_options)), getLDAPConfigHash());

			foreach (array ('server' => 'ldap2.example.com', 'search_bind_rdn' => 'cn=other,dc=example,dc=com', 'cache_expiry' => 601) as $name => $value)
			{
				$LDAP_options = array ($name => $value) + $base;
				$this->assertNotSame ($without, getLDAPConfigHash(), $name);
			}
			$LDAP_options = $base + array ('use_tls' => 2);
			$this->assertNotSame ($without, getLDAPConfigHash());
		}
		finally
		{
			$LDAP_options = $saved;
		}
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
