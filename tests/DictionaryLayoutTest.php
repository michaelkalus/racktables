<?php

// A blade server model that spans more than one chassis slot carries the
// %Lrows,cols% marker in its dictionary record, which extractLayout() turns
// into the number of slots the blade occupies when the chassis is rendered.
class DictionaryLayoutTest extends RTTestCase
{
	public function providerFullHeightBlades ()
	{
		return array
		(
			array (1521), // Dell PowerEdge M610x
			array (1522), // Dell PowerEdge M710
			array (1523), // Dell PowerEdge M805
			array (1524), // Dell PowerEdge M905
			array (1525), // Dell PowerEdge M910
			array (1698), // Dell PowerEdge M915
			array (2032), // Dell PowerEdge M820
		);
	}

	/**
	 * @group small
	 * @dataProvider providerFullHeightBlades
	 */
	public function testFullHeightBladeLayout ($dict_key)
	{
		$result = usePreparedSelectBlade ('SELECT dict_value FROM Dictionary WHERE dict_key = ?', array ($dict_key));
		$record = array ('value' => $result->fetchColumn());
		extractLayout ($record);
		$this->assertArrayHasKey ('rows', $record, $record['value']);
		$this->assertEquals (2, $record['rows']);
		$this->assertEquals (1, $record['cols']);
	}
}
