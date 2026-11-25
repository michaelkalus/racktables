<?php

// The Live PTR tab of an IPv4 network must not offer, and the import must
// not change, reserved addresses and the network and broadcast addresses.
class LivePTRImportTest extends RTTestCase
{
	protected $net_id, $saved_rackcode;

	public function setUp () : void
	{
		global $rackCode, $log_messages;
		$this->saved_rackcode = $rackCode;
		$log_messages = array();
		// a connected network gets reserved 'network' and 'broadcast' names
		$this->net_id = createIPv4Prefix ('203.0.113.0/29', $this->myString ('net'), TRUE);
		updateAddress (ip4_parse ('203.0.113.1'), 'gateway', 'yes');
		updateAddress (ip4_parse ('203.0.113.2'), '', 'yes');
		updateAddress (ip4_parse ('203.0.113.4'), 'oldname', 'no');
		updateAddress (ip4_parse ('203.0.113.5'), 'mismatch', 'no', 'a comment');
	}

	public function tearDown () : void
	{
		global $sic, $pageno, $tabno, $log_messages, $rackCode;
		$rackCode = $this->saved_rackcode;
		for ($i = 0; $i <= 7; $i++)
			updateAddress (ip4_parse ("203.0.113.{$i}"), '', 'no', '');
		usePreparedExecuteBlade
		(
			'DELETE FROM IPv4Log WHERE ip BETWEEN ? AND ?',
			array (ip4_bin2db (ip4_parse ('203.0.113.0')), ip4_bin2db (ip4_parse ('203.0.113.7')))
		);
		destroyIPv4Prefix ($this->net_id);
		foreach (array_keys ($_REQUEST) as $argname)
			unset ($sic[$argname], $_REQUEST[$argname]);
		$pageno = 'index';
		$tabno = 'default';
		fixContext();
		$log_messages = array();
	}

	protected function setRequestArgs ($args)
	{
		global $sic;
		foreach ($args as $argname => $argvalue)
			$sic[$argname] = $_REQUEST[$argname] = $argvalue;
	}

	protected function addr ($ip)
	{
		$addr = getIPv4Address (ip4_parse ($ip));
		return array ($addr['name'], $addr['reserved'], $addr['comment']);
	}

	// run the ophandler the way index.php?module=redirect does, with every
	// given address ticked for import (as "toggle selection" does)
	protected function runImport ($records)
	{
		global $ophandler, $pageno, $tabno, $op;
		$pageno = 'ipv4net';
		$tabno = 'liveptr';
		$op = 'importPTRData';
		$args = array ('id' => $this->net_id, 'addrcount' => count ($records));
		$i = 1;
		foreach ($records as $ip => $rec)
		{
			list ($ptrname, $rsvd) = $rec;
			$args["addr_{$i}"] = $ip;
			$args["descr_{$i}"] = $ptrname;
			$args["rsvd_{$i}"] = $rsvd;
			$args["import_{$i}"] = 'on';
			$i++;
		}
		$this->setRequestArgs ($args);
		call_user_func ($ophandler[$pageno][$tabno][$op]);
	}

	protected function logMessages ()
	{
		global $log_messages;
		return $log_messages;
	}

	/**
	 * @group small
	 */
	public function testImportSkipsReservedAddresses ()
	{
		$this->runImport (array
		(
			'203.0.113.0' => array ('', 'yes'),
			'203.0.113.1' => array ('gw.example.com', 'yes'),
			'203.0.113.2' => array ('reserved.example.com', 'yes'),
			'203.0.113.3' => array ('host3.example.com', 'no'),
			'203.0.113.4' => array ('', 'no'),
			'203.0.113.5' => array ('host5.example.com', 'no'),
			'203.0.113.7' => array ('bcast.example.com', 'yes'),
		));
		// reserved addresses are left alone
		$this->assertEquals (array ('network', 'yes', ''), $this->addr ('203.0.113.0'));
		$this->assertEquals (array ('gateway', 'yes', ''), $this->addr ('203.0.113.1'));
		$this->assertEquals (array ('', 'yes', ''), $this->addr ('203.0.113.2'));
		$this->assertEquals (array ('broadcast', 'yes', ''), $this->addr ('203.0.113.7'));
		// other addresses get the PTR name as before
		$this->assertEquals (array ('host3.example.com', 'no', ''), $this->addr ('203.0.113.3'));
		$this->assertEquals (array ('', 'no', ''), $this->addr ('203.0.113.4'));
		$this->assertEquals (array ('host5.example.com', 'no', 'a comment'), $this->addr ('203.0.113.5'));
		$this->assertEquals
		(
			array
			(
				array ('c' => 26, 'a' => array (3)),
				array ('c' => 200, 'a' => array ('Skipped 4 reserved address(es)')),
			),
			$this->logMessages()
		);
	}

	/**
	 * @group small
	 */
	public function testImportStaleForm ()
	{
		// the reserved flag in the form is outdated or crafted
		$this->runImport (array
		(
			'203.0.113.1' => array ('gw.example.com', 'no'),
			'203.0.113.3' => array ('host3.example.com', 'yes'),
		));
		$this->assertEquals (array ('gateway', 'yes', ''), $this->addr ('203.0.113.1'));
		$this->assertEquals (array ('host3.example.com', 'no', ''), $this->addr ('203.0.113.3'));
	}

	/**
	 * @group small
	 */
	public function testImportLargerSelection ()
	{
		// Every address of a /26 ticked, most of them without a database row,
		// and two addresses outside of the network.
		$net_id = createIPv4Prefix ('203.0.113.64/26', $this->myString ('net26'), TRUE);
		$saved_net_id = $this->net_id;
		$this->net_id = $net_id;
		try
		{
			$records = $expected = array();
			$ngood = $nskip = 0;
			for ($i = 64; $i < 128; $i++)
			{
				$ip = "203.0.113.{$i}";
				$ptrname = $i % 5 ? "h{$i}.example.com" : '';
				$records[$ip] = array ($ptrname, 'no');
				if ($i == 64)
					$expected[$ip] = array ('network', 'yes', '');
				elseif ($i == 127)
					$expected[$ip] = array ('broadcast', 'yes', '');
				elseif ($i % 4 == 0)
				{
					updateAddress (ip4_parse ($ip), "rsv{$i}", 'yes');
					$expected[$ip] = array ("rsv{$i}", 'yes', '');
				}
				else
				{
					if ($i % 3 == 0)
						updateAddress (ip4_parse ($ip), "old{$i}", 'no');
					$expected[$ip] = array ($ptrname, 'no', '');
				}
				if ($expected[$ip][1] == 'yes')
					$nskip++;
				else
					$ngood++;
			}
			$records['203.0.113.1'] = array ('gw.example.com', 'no');
			$records['198.51.100.1'] = array ('other.example.com', 'no');
			$this->runImport ($records);
			foreach ($expected as $ip => $addr)
				$this->assertEquals ($addr, $this->addr ($ip), $ip);
			$this->assertEquals (array ('gateway', 'yes', ''), $this->addr ('203.0.113.1'));
			$this->assertEquals (array ('', 'no', ''), $this->addr ('198.51.100.1'));
			$this->assertEquals
			(
				array
				(
					array ('c' => 141, 'a' => array (2, $ngood)),
					array ('c' => 200, 'a' => array ("Skipped {$nskip} reserved address(es)")),
				),
				$this->logMessages()
			);
		}
		finally
		{
			$this->net_id = $saved_net_id;
			for ($i = 64; $i < 128; $i++)
				updateAddress (ip4_parse ("203.0.113.{$i}"), '', 'no', '');
			usePreparedExecuteBlade
			(
				'DELETE FROM IPv4Log WHERE ip BETWEEN ? AND ?',
				array (ip4_bin2db (ip4_parse ('203.0.113.64')), ip4_bin2db (ip4_parse ('203.0.113.127')))
			);
			destroyIPv4Prefix ($net_id);
		}
	}

	/**
	 * @group small
	 */
	public function testImportSkipsUnreservedNetworkAndBroadcast ()
	{
		// the network and broadcast addresses are ignored by their names
		updateAddress (ip4_parse ('203.0.113.0'), 'network', 'no');
		updateAddress (ip4_parse ('203.0.113.7'), 'broadcast', 'no');
		$this->runImport (array
		(
			'203.0.113.0' => array ('net.example.com', 'no'),
			'203.0.113.7' => array ('', 'no'),
		));
		$this->assertEquals (array ('network', 'no', ''), $this->addr ('203.0.113.0'));
		$this->assertEquals (array ('broadcast', 'no', ''), $this->addr ('203.0.113.7'));
	}

	/**
	 * @group small
	 */
	public function testFormOffersNoReservedAddresses ()
	{
		global $pageno, $tabno, $rackCode;
		$parsed = getRackCode ('allow true');
		$this->assertEquals ('ACK', $parsed['result']);
		$rackCode = $parsed['load'];
		$pageno = 'ipv4net';
		$tabno = 'liveptr';
		$this->setRequestArgs (array ('id' => $this->net_id));
		fixContext();
		// The page resolves PTR names of the network, which normally do not
		// exist for this documentation prefix. The reserved rows must have no
		// checkbox whatever the DNS says, a named unreserved address without
		// the same PTR name must have one.
		$output = getOutputOf ('renderLivePTR', $this->net_id);
		$this->assertStringContainsString ('Import selected records', $output);
		$rows = array();
		foreach (explode ('<tr', $output) as $row)
			if (preg_match ('/>(203\.0\.113\.\d)</', $row, $m))
				$rows[$m[1]] = FALSE !== strpos ($row, 'type=checkbox');
		$this->assertCount (8, $rows);
		$this->assertFalse ($rows['203.0.113.0']);
		$this->assertFalse ($rows['203.0.113.1']);
		$this->assertFalse ($rows['203.0.113.2']);
		$this->assertFalse ($rows['203.0.113.7']);
		$this->assertTrue ($rows['203.0.113.4']);
		$this->assertTrue ($rows['203.0.113.5']);
	}
}
