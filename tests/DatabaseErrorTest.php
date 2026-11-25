<?php

// A value that does not fit the column must end up as the ordinary database
// error shown to the user, not as an unhandled PDOException.
class DatabaseErrorTest extends RTTestCase
{
	protected $object_id;

	public function setUp () : void
	{
		$this->object_id = commitAddObject ($this->myString ('object'), NULL, 4, NULL);
	}

	public function tearDown () : void
	{
		commitDeleteObject ($this->object_id);
	}

	protected function assertDatabaseError ($message, $callback)
	{
		try
		{
			$callback();
			$this->fail ('the change must fail');
		}
		catch (RTDatabaseError $e)
		{
			$this->assertSame ($message, $e->getMessage());
		}
		$this->assertFalse (isTransactionActive());
	}

	protected function objectName ()
	{
		$result = usePreparedSelectBlade ('SELECT name FROM Object WHERE id = ?', array ($this->object_id));
		return $result->fetchColumn();
	}

	/**
	 * @group small
	 */
	public function testDataTooLong ()
	{
		$object_id = $this->object_id;
		$this->assertDatabaseError
		(
			"data too long for column 'name'",
			function () use ($object_id)
			{
				commitRenameObject ($object_id, str_repeat ('x', 300));
			}
		);
		$this->assertSame ($this->myString ('object'), $this->objectName());
	}

	/**
	 * @group small
	 */
	public function testOutOfRange ()
	{
		$object_id = $this->object_id;
		$this->assertDatabaseError
		(
			"out of range value for column 'uint_value'",
			function () use ($object_id)
			{
				// attribute 17 (DRAM, MB) is an unsigned integer
				usePreparedInsertBlade
				(
					'AttributeValue',
					array ('object_id' => $object_id, 'object_tid' => 4, 'attr_id' => 17, 'uint_value' => '99999999999')
				);
			}
		);
	}

	/**
	 * @group small
	 */
	public function testCannotBeNull ()
	{
		$object_id = $this->object_id;
		$this->assertDatabaseError
		(
			"value cannot be NULL for column 'objtype_id'",
			function () use ($object_id)
			{
				usePreparedUpdateBlade ('Object', array ('objtype_id' => NULL), array ('id' => $object_id));
			}
		);
	}

	public function providerServerMessage ()
	{
		return array
		(
			array ('22001', 1406, "Data too long for column 'name' at row 1", "data too long for column 'name'"),
			array ('22001', 1406, 'Data too long', 'data too long'),
			array ('22003', 1264, "Out of range value for column 'uint_value' at row 1", "out of range value for column 'uint_value'"),
			array ('23000', 1048, "Column 'objtype_id' cannot be null", "value cannot be NULL for column 'objtype_id'"),
			// a column name that is not a plain identifier is not repeated
			array ('23000', 1048, "Column '<b>' cannot be null", 'value cannot be NULL'),
		);
	}

	/**
	 * @group small
	 * @dataProvider providerServerMessage
	 */
	public function testServerMessage ($sqlstate, $errno, $server_message, $expected)
	{
		$converted = convertPDOException (new DatabaseErrorTestPDOException ($sqlstate, $errno, $server_message));
		$this->assertInstanceOf ('RTDatabaseError', $converted);
		$this->assertSame ($expected, $converted->getMessage());
	}
}

// a PDOException as PDO throws it for a server error
class DatabaseErrorTestPDOException extends PDOException
{
	public function __construct ($sqlstate, $errno, $server_message)
	{
		parent::__construct ("SQLSTATE[{$sqlstate}]: {$errno} {$server_message}");
		$this->code = $sqlstate;
		$this->errorInfo = array ($sqlstate, $errno, $server_message);
	}
}
