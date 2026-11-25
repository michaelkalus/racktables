<?php

class TagsPickerOrderTest extends RTTestCase
{
	protected $tag_ids = array();

	public function setUp () : void
	{
		global $taglist;

		// The IDs grow in an order that differs from the name order.
		$tree = array
		(
			array ('zeta', NULL),
			array ('Alpha', NULL),
			array ('beta', NULL),
			array ("\xc3\x89mile", NULL), // E with acute
			array ('mid', 'zeta'),
			array ('Apple', 'zeta'),
			array ('kilo', 'zeta'),
			array ('z2', 'Apple'),
			array ('a2', 'Apple'),
			array ('M2', 'Apple'),
			array ("\xc3\xa9clair", 'Apple'), // e with acute
		);
		foreach ($tree as $node)
		{
			list ($name, $parent) = $node;
			usePreparedInsertBlade ('TagTree', array
			(
				'tag' => $this->myString ($name),
				'parent_id' => $parent === NULL ? NULL : $this->tag_ids[$parent],
			));
			$this->tag_ids[$name] = lastInsertID();
		}
		$taglist = addTraceToNodes (getTagList());
	}

	public function tearDown () : void
	{
		global $taglist;
		foreach (array_reverse ($this->tag_ids) as $tag_id)
			usePreparedDeleteBlade ('TagTree', array ('id' => $tag_id));
		$taglist = addTraceToNodes (getTagList());
	}

	// Return the decoded value of the "taglist" JavaScript variable.
	protected function getPickerTaglist ()
	{
		global $html_headers;
		enableTagsPicker();
		foreach ($html_headers as $group)
			foreach ($group as $header)
				if (preg_match ('/^<script type="text\/javascript">\nvar taglist = (.*);\n<\/script>\n$/s', $header, $m))
					return json_decode ($m[1], TRUE);
		$this->fail ('taglist script not found');
	}

	/**
	 * enableTagsPicker() emits the tag list only once per process, so run
	 * this test in a process that has not rendered a tag picker yet.
	 *
	 * @group small
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testPickerOrder ()
	{
		global $taglist;
		$js_taglist = $this->getPickerTaglist();

		// The data stays keyed by tag ID with the fields the picker uses.
		$this->assertEquals (array_keys ($taglist), array_keys ($js_taglist));
		foreach ($this->tag_ids as $tag_id)
		{
			$this->assertEquals ($taglist[$tag_id]['tag'], $js_taglist[$tag_id]['tag']);
			$this->assertEquals ($taglist[$tag_id]['trace'], $js_taglist[$tag_id]['trace']);
		}

		// A JavaScript object iterates integer keys in ascending order, so the
		// SQL order must travel in the data itself.
		$sort_index = array();
		foreach ($js_taglist as $tag_id => $taginfo)
		{
			$this->assertArrayHasKey ('sort_index', $taginfo);
			$sort_index[$tag_id] = $taginfo['sort_index'];
		}
		$this->assertEquals (range (0, count ($taglist) - 1), array_values ($sort_index));

		asort ($sort_index);
		$names = array();
		$mine = array_flip ($this->tag_ids);
		foreach (array_keys ($sort_index) as $tag_id)
			if (array_key_exists ($tag_id, $mine))
				$names[] = $mine[$tag_id];
		$expected = array
		(
			'a2',
			'Alpha',
			'Apple',
			'beta',
			"\xc3\xa9clair",
			"\xc3\x89mile",
			'kilo',
			'M2',
			'mid',
			'z2',
			'zeta',
		);
		$this->assertSame ($expected, $names);
	}
}
