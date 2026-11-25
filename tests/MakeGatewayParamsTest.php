<?php

require_once '../wwwroot/inc/remote.php';

class MakeGatewayParamsTest extends RTTestCase
{
	protected static function settings ($protocol)
	{
		return array
		(
			'hostname' => '192.0.2.1',
			'protocol' => $protocol,
			'port' => NULL,
			'prompt' => '[>#]$',
			'username' => 'rtuser',
			'password' => 'Secret80',
			'timeout' => 15,
			'connect_timeout' => 2,
			'prompt_delay' => 0.001,
			'sudo_user' => NULL,
			'identity_file' => NULL,
			'tolerate_remote_errors' => TRUE,
		);
	}

	protected static function make ($settings, &$commands)
	{
		return makeGatewayParams (0, array (&$settings), array (&$commands));
	}

	/**
	 * @group small
	 */
	public function testSSHNoKeyCredentials ()
	{
		$commands = "show version\n";
		$params = self::make (self::settings ('sshnokey'), $commands);
		$this->assertSame ('rtuser', $params['username']);
		$this->assertArrayNotHasKey ('password', $params);
		$this->assertNotContains ('Secret80', $params);
		$this->assertContains ('--password-stdin', $params);
		$this->assertSame ('192.0.2.1', end ($params));
		// the gateway reads the password line itself, the username is not sent
		$this->assertSame ("Secret80\nshow version\n", $commands);
	}

	/**
	 * @group small
	 */
	public function testSSHNoKeyNoPassword ()
	{
		$settings = self::settings ('sshnokey');
		$settings['password'] = NULL;
		$commands = "show version\n";
		$params = self::make ($settings, $commands);
		$this->assertNotContains ('--password-stdin', $params);
		$this->assertSame ("show version\n", $commands);
	}

	/**
	 * @group small
	 */
	public function testSSHNoKeyPortAndProto ()
	{
		$settings = self::settings ('sshnokey');
		$commands = '';
		$params = self::make ($settings, $commands);
		$this->assertArrayNotHasKey ('port', $params);
		$settings['port'] = 2222;
		$settings['proto'] = 4;
		$params = self::make ($settings, $commands);
		$this->assertArrayHasKey ('port', $params);
		$this->assertSame (2222, $params['port']);
		$this->assertArrayNotHasKey ('proto', $params);
	}

	public function providerPrependedCredentials ()
	{
		return array
		(
			array ('telnet'),
			array ('netcat'),
		);
	}

	/**
	 * @group small
	 * @dataProvider providerPrependedCredentials
	 */
	public function testPrependedCredentials ($protocol)
	{
		$commands = "show version\n";
		self::make (self::settings ($protocol), $commands);
		$this->assertSame ("rtuser\nSecret80\nshow version\n", $commands);
	}
}
