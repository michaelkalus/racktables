<?php

// Password hashes of local accounts: format rules, verification, the upgrade
// of legacy SHA-1 hashes on login and the operations that set a password.
class PasswordHashTest extends RTTestCase
{
	const PASSWORD = 'correct horse battery staple';
	private $user_name;
	private $user_id;
	private $saved_globals;

	public function setUp () : void
	{
		global $remote_username, $user_auth_src;
		$this->saved_globals = array ($remote_username, $user_auth_src);
		$this->user_name = $this->myString ('pwuser');
		$this->user_id = commitCreateUserAccount ($this->user_name, '', makePasswordHash (self::PASSWORD));
	}

	public function tearDown () : void
	{
		global $remote_username, $user_auth_src, $sic;
		list ($remote_username, $user_auth_src) = $this->saved_globals;
		foreach (array ('username', 'realname', 'password', 'user_id', 'oldpassword', 'newpassword1', 'newpassword2') as $name)
			unset ($_REQUEST[$name], $sic[$name]);
		$id = getUserIDByUsername ($this->user_name);
		if ($id !== NULL)
			commitDeleteUserAccount ($id);
		$id = getUserIDByUsername ($this->user_name . '-new');
		if ($id !== NULL)
			commitDeleteUserAccount ($id);
	}

	// as transformRequestData() does; every call starts a new "request"
	private function setRequest ($args)
	{
		global $sic, $entityCache;
		$entityCache = array();
		foreach ($args as $name => $value)
		{
			$sic[$name] = $value;
			$_REQUEST[$name] = htmlspecialchars ($value, ENT_QUOTES, 'UTF-8');
		}
	}

	// Bypasses the format check of commitUpdateUserAccount().
	private function storeHash ($hash)
	{
		global $entityCache;
		usePreparedUpdateBlade ('UserAccount', array ('user_password_hash' => $hash), array ('user_id' => $this->user_id));
		$entityCache = array();
	}

	private function storedHash ($user_id = NULL)
	{
		$user = spotEntity ('user', $user_id === NULL ? $this->user_id : $user_id, TRUE);
		return $user['user_password_hash'];
	}

	private function lastMessageCode ()
	{
		global $log_messages;
		$last = end ($log_messages);
		return $last['c'];
	}

	/**
	 * @group small
	 */
	public function testFormats ()
	{
		$legacy = sha1 (self::PASSWORD);
		$modern = makePasswordHash (self::PASSWORD);
		$this->assertTrue (isLegacyPasswordHash ($legacy));
		$this->assertFalse (isModernPasswordHash ($legacy));
		$this->assertTrue (isModernPasswordHash ($modern));
		$this->assertFalse (isLegacyPasswordHash ($modern));
		$this->assertSame ('$', $modern[0]);
		$this->assertNotSame ($modern, makePasswordHash (self::PASSWORD)); // salted
		foreach (array (NULL, '', strtoupper ($legacy), $legacy . "\n", substr ($legacy, 1), $legacy . '0', 'x' . substr ($legacy, 1),
			substr ($modern, 0, -1), '$2y$10$', '$' . $legacy, 'password', ' ' . $modern) as $bad)
		{
			$this->assertFalse (isLegacyPasswordHash ($bad), var_export ($bad, TRUE));
			$this->assertFalse (isModernPasswordHash ($bad), var_export ($bad, TRUE));
		}
	}

	/**
	 * @group small
	 */
	public function testLegacyHash ()
	{
		$legacy = sha1 (self::PASSWORD);
		$this->assertTrue (passwordMatchesHash (self::PASSWORD, $legacy));
		$this->assertFalse (passwordMatchesHash ('wrong', $legacy));
		$this->assertFalse (passwordMatchesHash ('', $legacy));
		$this->assertFalse (passwordMatchesHash ($legacy, $legacy)); // the hash is not the password
		$this->assertTrue (passwordHashNeedsUpgrade ($legacy));
		// sha1('') must not let an empty password in
		$this->assertFalse (passwordMatchesHash ('', sha1 ('')));
	}

	/**
	 * @group small
	 */
	public function testModernHash ()
	{
		$modern = makePasswordHash (self::PASSWORD);
		$this->assertTrue (passwordMatchesHash (self::PASSWORD, $modern));
		$this->assertFalse (passwordMatchesHash ('wrong', $modern));
		$this->assertFalse (passwordMatchesHash ('', $modern));
		$this->assertFalse (passwordMatchesHash ($modern, $modern));
		$this->assertFalse (passwordMatchesHash (self::PASSWORD . "\0x", $modern));
		// bcrypt would ignore the bytes after the first 72
		$long = str_repeat ('x', 72);
		$this->assertTrue (passwordMatchesHash ($long, makePasswordHash ($long)));
		$this->assertFalse (passwordMatchesHash ($long . 'y', makePasswordHash ($long)));
		$this->assertFalse (passwordHashNeedsUpgrade ($modern));
		// a hash of the same algorithm with a lower cost than the current default
		$cheap = password_hash (self::PASSWORD, PASSWORD_BCRYPT, array ('cost' => 4));
		$this->assertTrue (passwordMatchesHash (self::PASSWORD, $cheap));
		$this->assertSame (password_needs_rehash ($cheap, PASSWORD_DEFAULT), passwordHashNeedsUpgrade ($cheap));
	}

	/**
	 * @group small
	 */
	public function testMalformedStoredValue ()
	{
		$legacy = sha1 (self::PASSWORD);
		foreach (array (NULL, '', strtoupper ($legacy), ' ' . $legacy, substr ($legacy, 0, 39), self::PASSWORD, '$' . self::PASSWORD) as $stored)
		{
			$this->assertFalse (passwordMatchesHash (self::PASSWORD, $stored), var_export ($stored, TRUE));
			$this->assertFalse (passwordHashNeedsUpgrade ($stored), var_export ($stored, TRUE));
		}
	}

	/**
	 * @group small
	 */
	public function testMakeHashRejectsEmptyAndNUL ()
	{
		foreach (array ('', "secret\0b", 'secret' . str_repeat ('x', 67)) as $password)
			try
			{
				makePasswordHash ($password);
				$this->fail ('makePasswordHash() accepted ' . var_export ($password, TRUE));
			}
			catch (InvalidArgException $e)
			{
				// the error message must not disclose the password
				$this->assertStringNotContainsString ('secret', $e->getMessage());
			}
	}

	/**
	 * @group small
	 */
	public function testCommitRejectsOtherFormats ()
	{
		foreach (array (sha1 ('x'), 'password', '') as $hash)
			try
			{
				commitCreateUserAccount ($this->user_name . '-new', '', $hash);
				$this->fail ('commitCreateUserAccount() accepted ' . var_export ($hash, TRUE));
			}
			catch (InvalidArgException $e)
			{
				$this->assertNull (getUserIDByUsername ($this->user_name . '-new'));
			}
		$before = $this->storedHash();
		foreach (array (sha1 ('x'), 'password') as $hash)
			try
			{
				commitUpdateUserAccount ($this->user_id, $this->user_name, '', $hash);
				$this->fail ('commitUpdateUserAccount() accepted ' . var_export ($hash, TRUE));
			}
			catch (InvalidArgException $e)
			{
				$this->assertSame ($before, $this->storedHash());
			}
	}

	/**
	 * @group small
	 */
	public function testLoginUpgradesLegacyHash ()
	{
		$legacy = sha1 (self::PASSWORD);
		$this->storeHash ($legacy);
		// a wrong password changes nothing
		$this->assertFalse (authenticated_via_database (spotEntity ('user', $this->user_id, TRUE), 'wrong'));
		$this->assertSame ($legacy, $this->storedHash());
		// the right one is accepted and the hash replaced
		$this->assertTrue (authenticated_via_database (spotEntity ('user', $this->user_id, TRUE), self::PASSWORD));
		$new = $this->storedHash();
		$this->assertTrue (isModernPasswordHash ($new));
		$this->assertTrue (passwordMatchesHash (self::PASSWORD, $new));
		// the next login keeps the new hash
		$this->assertTrue (authenticated_via_database (spotEntity ('user', $this->user_id, TRUE), self::PASSWORD));
		$this->assertSame ($new, $this->storedHash());
		$this->assertFalse (authenticated_via_database (spotEntity ('user', $this->user_id, TRUE), $legacy));
	}

	// A password that the new scheme does not accept keeps its legacy hash.
	/**
	 * @group small
	 */
	public function testLoginKeepsLegacyHashOfLongPassword ()
	{
		$long = str_repeat ('x', 73);
		$legacy = sha1 ($long);
		$this->storeHash ($legacy);
		$this->assertTrue (authenticated_via_database (spotEntity ('user', $this->user_id, TRUE), $long));
		$this->assertSame ($legacy, $this->storedHash());
		$this->assertFalse (authenticated_via_database (spotEntity ('user', $this->user_id, TRUE), substr ($long, 0, 72)));
	}

	// The stored value is replaced only if it is still the one that was verified.
	/**
	 * @group small
	 */
	public function testLoginUpgradeDoesNotOverwriteNewerHash ()
	{
		$legacy = sha1 (self::PASSWORD);
		$this->storeHash ($legacy);
		$userinfo = spotEntity ('user', $this->user_id, TRUE);
		$other = makePasswordHash ('other password');
		$this->storeHash ($other);
		$this->assertTrue (authenticated_via_database ($userinfo, self::PASSWORD));
		$this->assertSame ($other, $this->storedHash());
	}

	/**
	 * @group small
	 */
	public function testLoginInvalidStoredValues ()
	{
		$this->assertFalse (authenticated_via_database (array ('user_name' => 'x'), self::PASSWORD));
		foreach (array (NULL, '', self::PASSWORD) as $stored)
		{
			$this->storeHash ($stored);
			$this->assertFalse (authenticated_via_database (spotEntity ('user', $this->user_id, TRUE), self::PASSWORD));
			$this->assertSame ($stored, $this->storedHash());
		}
	}

	/**
	 * @group small
	 */
	public function testCreateUser ()
	{
		// the login uses the password as received, so must the hash
		$password = 'a&b <c> \'d"';
		$this->setRequest (array ('username' => $this->user_name . '-new', 'realname' => '', 'password' => $password));
		createUser();
		$id = getUserIDByUsername ($this->user_name . '-new');
		$this->assertNotNull ($id);
		$hash = $this->storedHash ($id);
		$this->assertTrue (isModernPasswordHash ($hash));
		$this->assertTrue (authenticated_via_database (spotEntity ('user', $id, TRUE), $password));
		$this->assertFalse (passwordMatchesHash (htmlspecialchars ($password, ENT_QUOTES, 'UTF-8'), $hash));
	}

	/**
	 * @group small
	 */
	public function testUpdateUserNewPassword ()
	{
		$this->storeHash (sha1 (self::PASSWORD));
		$this->setRequest (array ('user_id' => $this->user_id, 'username' => $this->user_name, 'realname' => 'Real Name', 'password' => 'new secret'));
		updateUser();
		$hash = $this->storedHash();
		$this->assertTrue (isModernPasswordHash ($hash));
		$this->assertTrue (passwordMatchesHash ('new secret', $hash));
		$this->assertFalse (passwordMatchesHash (self::PASSWORD, $hash));
	}

	// Issue #42: a blank password field keeps the password.
	/**
	 * @group small
	 */
	public function testUpdateUserBlankPassword ()
	{
		foreach (array (sha1 (self::PASSWORD), makePasswordHash (self::PASSWORD)) as $hash)
		{
			$this->storeHash ($hash);
			$this->setRequest (array ('user_id' => $this->user_id, 'username' => $this->user_name, 'realname' => 'Real Name', 'password' => ''));
			updateUser();
			$this->assertSame ($hash, $this->storedHash());
			$user = spotEntity ('user', $this->user_id, TRUE);
			$this->assertSame ('Real Name', $user['user_realname']);
			$this->assertTrue (authenticated_via_database ($user, self::PASSWORD));
		}
	}

	/**
	 * @group small
	 */
	public function testChangeMyPassword ()
	{
		global $remote_username, $user_auth_src;
		$remote_username = $this->user_name;
		$user_auth_src = 'database';
		$legacy = sha1 (self::PASSWORD);
		$this->storeHash ($legacy);

		// wrong old password
		$this->setRequest (array ('oldpassword' => 'wrong', 'newpassword1' => 'new secret', 'newpassword2' => 'new secret'));
		changeMyPassword();
		$this->assertSame (151, $this->lastMessageCode());
		$this->assertSame ($legacy, $this->storedHash());
		// the hash is not the password
		$this->setRequest (array ('oldpassword' => $legacy));
		changeMyPassword();
		$this->assertSame (151, $this->lastMessageCode());
		$this->assertSame ($legacy, $this->storedHash());
		// mismatch of the new passwords
		$this->setRequest (array ('oldpassword' => self::PASSWORD, 'newpassword2' => 'other'));
		changeMyPassword();
		$this->assertSame (152, $this->lastMessageCode());
		$this->assertSame ($legacy, $this->storedHash());
		// success with a legacy and then with a modern stored hash
		$this->setRequest (array ('oldpassword' => self::PASSWORD, 'newpassword2' => 'new secret'));
		changeMyPassword();
		$this->assertSame (51, $this->lastMessageCode());
		$hash = $this->storedHash();
		$this->assertTrue (isModernPasswordHash ($hash));
		$this->assertTrue (passwordMatchesHash ('new secret', $hash));
		$this->setRequest (array ('oldpassword' => 'new secret', 'newpassword1' => 'th&rd', 'newpassword2' => 'th&rd'));
		changeMyPassword();
		$this->assertSame (51, $this->lastMessageCode());
		$this->assertTrue (passwordMatchesHash ('th&rd', $this->storedHash()));
		$this->setRequest (array ('oldpassword' => 'th&rd', 'newpassword1' => 'fourth', 'newpassword2' => 'fourth'));
		changeMyPassword();
		$this->assertSame (51, $this->lastMessageCode());
		$this->assertTrue (passwordMatchesHash ('fourth', $this->storedHash()));
	}

	// The upgrader authenticates the administrator by itself, before init.php.
	/**
	 * @group small
	 */
	public function testUpgraderAuthentication ()
	{
		require_once '../wwwroot/inc/upgrade.php';
		$admin = spotEntity ('user', 1, TRUE);
		$saved = $admin['user_password_hash'];
		try
		{
			foreach (array (sha1 (self::PASSWORD), makePasswordHash (self::PASSWORD)) as $hash)
			{
				usePreparedUpdateBlade ('UserAccount', array ('user_password_hash' => $hash), array ('user_id' => 1));
				$this->assertTrue (authenticate_admin ($admin['user_name'], self::PASSWORD));
				$this->assertFalse (authenticate_admin ($admin['user_name'], 'wrong'));
				$this->assertFalse (authenticate_admin ($admin['user_name'], $hash));
				$this->assertFalse (authenticate_admin ($admin['user_name'] . 'x', self::PASSWORD));
				$this->assertFalse (authenticate_admin ($this->user_name, self::PASSWORD));
				// the upgrader never writes
				$this->assertSame ($hash, spotEntity ('user', 1, TRUE)['user_password_hash']);
			}
		}
		finally
		{
			usePreparedUpdateBlade ('UserAccount', array ('user_password_hash' => $saved), array ('user_id' => 1));
		}
	}
}
