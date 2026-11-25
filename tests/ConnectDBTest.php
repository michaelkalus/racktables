<?php

// connectDB() passes the optional MySQL driver attributes from secret.php
// without the constants that are deprecated since PHP 8.5.
class ConnectDBTest extends RTTestCase
{
	protected $deprecations = array();
	protected $saved_bufsize = NULL;

	public function setUp () : void
	{
		global $pdo_bufsize;
		$this->saved_bufsize = $pdo_bufsize;
		set_error_handler (array ($this, 'collectDeprecation'), E_DEPRECATED | E_USER_DEPRECATED);
	}

	public function tearDown () : void
	{
		global $pdo_bufsize;
		restore_error_handler();
		$pdo_bufsize = $this->saved_bufsize;
		connectDB();
	}

	public function collectDeprecation ($errno, $errstr)
	{
		$this->deprecations[] = $errstr;
		return TRUE;
	}

	/**
	 * @group small
	 */
	public function testDriverAttributes ()
	{
		foreach (array ('INIT_COMMAND', 'SSL_KEY', 'SSL_CERT', 'SSL_CA') as $name)
			$this->assertIsInt (getPDOMySQLAttr ($name), $name);
		$this->assertNull (getPDOMySQLAttr ('NO_SUCH_ATTRIBUTE'));
		$this->assertSame (array(), $this->deprecations);
	}

	// MAX_BUFFER_SIZE exists only when pdo_mysql is not built with mysqlnd.
	/**
	 * @group small
	 */
	public function testBufferSize ()
	{
		global $pdo_bufsize, $dbxlink;
		$pdo_bufsize = 2 * 1024 * 1024;
		connectDB();
		$this->assertInstanceOf ('PDO', $dbxlink);
		$attr = getPDOMySQLAttr ('MAX_BUFFER_SIZE');
		if ($attr !== NULL)
			$this->assertEquals ($pdo_bufsize, $dbxlink->getAttribute ($attr));
		$this->assertSame (array(), $this->deprecations);
	}
}
