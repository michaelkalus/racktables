<?php

// Consistency of the $known_switches table in snmp.php. Every entry must name
// an existing hardware model in a chapter that SNMP sync can assign, list
// only port processors that exist in $iftable_processors and have a display
// text, otherwise SNMP sync of the device creates no ports for the missing
// processors or sets a wrong HW type.
class KnownSwitchesTest extends RTTestCase
{
	// Entries whose dict_key is intentionally outside the HW type chapters of
	// the object types SNMP sync works on.
	protected static $chapter_exceptions = array
	(
		'9.1.282', // Catalyst 6506 exists only as a network chassis model
		'2636.1.1.1.2.41', // SRX100 exists only as a network security model
	);

	// Processors that are referenced but not defined, because the intended
	// definition cannot be determined without the device.
	protected static $processor_exceptions = array
	(
		// TL-SG2216: the ifDescr form of the combo ports is unknown, the
		// neighbouring TP-Link processors use two different forms.
		'11863.1.1.12' => array ('tplink-15-to-16-combo-1000SFP'),
	);

	/**
	 * @group small
	 */
	public function testEntryKeys ()
	{
		global $known_switches;
		$failures = array();
		foreach ($known_switches as $oid => $entry)
		{
			if (! preg_match ('/^[[:digit:]]+(\.[[:digit:]]+)*$/', $oid))
				$failures[] = "{$oid}: malformed OID";
			$keys = array_keys ($entry);
			sort ($keys);
			if ($keys != array ('dict_key', 'processors', 'text') && $keys != array ('dict_key', 'ifDescrOID', 'processors', 'text'))
				$failures[] = "{$oid}: keys " . implode (', ', $keys);
			if (! is_int (array_fetch ($entry, 'dict_key', NULL)))
				$failures[] = "{$oid}: dict_key is not an integer";
			if (! is_array (array_fetch ($entry, 'processors', NULL)) || ! count ($entry['processors']))
				$failures[] = "{$oid}: no processors";
			if (array_key_exists ('ifDescrOID', $entry) && ! preg_match ('/^[[:alpha:]]+$/', $entry['ifDescrOID']))
				$failures[] = "{$oid}: malformed ifDescrOID";
		}
		$this->assertSame (array(), $failures);
	}

	/**
	 * @group small
	 */
	public function testEntryText ()
	{
		global $known_switches;
		$failures = array();
		foreach ($known_switches as $oid => $entry)
		{
			$text = array_fetch ($entry, 'text', NULL);
			if
			(
				! is_string ($text) ||
				$text == '' ||
				trim ($text) !== $text ||
				! preg_match ('/^[\x20-\x7e]+$/', $text) || // one line of printable ASCII
				preg_match ('/%G(PASS|SKIP)%|\[\[|\]\]/', $text) // dictionary markup
			)
				$failures[] = "{$oid}: '{$text}'";
		}
		$this->assertSame (array(), $failures);
	}

	/**
	 * @group small
	 */
	public function testDictKeys ()
	{
		global $known_switches;
		$chapters = array();
		$result = usePreparedSelectBlade ('SELECT chapter_id FROM AttributeMap WHERE attr_id = 2 AND objtype_id IN (7, 8, 965)');
		foreach ($result->fetchAll (PDO::FETCH_COLUMN, 0) as $chapter_id)
			$chapters[] = $chapter_id;
		$this->assertCount (3, $chapters);
		$failures = array();
		foreach ($known_switches as $oid => $entry)
		{
			$result = usePreparedSelectBlade ('SELECT chapter_id FROM Dictionary WHERE dict_key = ?', array ($entry['dict_key']));
			$chapter_id = $result->fetchColumn();
			if ($chapter_id === FALSE)
				$failures[] = "{$oid}: dict_key {$entry['dict_key']} does not exist";
			elseif (! in_array ($chapter_id, $chapters) && ! in_array ($oid, self::$chapter_exceptions, TRUE))
				$failures[] = "{$oid}: dict_key {$entry['dict_key']} is in chapter {$chapter_id}";
		}
		$this->assertSame (array(), $failures);
	}

	/**
	 * @group small
	 */
	public function testProcessors ()
	{
		global $known_switches, $iftable_processors;
		$failures = array();
		foreach ($known_switches as $oid => $entry)
			foreach ($entry['processors'] as $processor_name)
				if
				(
					! array_key_exists ($processor_name, $iftable_processors) &&
					! in_array ($processor_name, array_fetch (self::$processor_exceptions, $oid, array()), TRUE)
				)
					$failures[] = "{$oid}: {$processor_name}";
		$this->assertSame (array(), $failures);
	}

	// A listed exception that is no longer needed must be removed.
	/**
	 * @group small
	 */
	public function testExceptionsStillNeeded ()
	{
		global $known_switches, $iftable_processors;
		foreach (self::$processor_exceptions as $oid => $names)
			foreach ($names as $processor_name)
			{
				$this->assertContains ($processor_name, $known_switches[$oid]['processors']);
				$this->assertArrayNotHasKey ($processor_name, $iftable_processors);
			}
		foreach (self::$chapter_exceptions as $oid)
			$this->assertArrayHasKey ($oid, $known_switches);
	}
}
