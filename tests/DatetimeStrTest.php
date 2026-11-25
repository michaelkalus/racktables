<?php

// This covers the conversion between UNIX timestamps and date/time strings
// formatted per the DATETIME_FORMAT configuration variable, which uses the
// strftime() syntax.
class DatetimeStrTest extends RTTestCase
{
	protected static $old_format;
	protected static $old_tz;

	public static function setUpBeforeClass () : void
	{
		self::$old_format = getConfigVar ('DATETIME_FORMAT');
		self::$old_tz = date_default_timezone_get();
		// A time zone with DST and a non-zero offset makes any mix-up
		// between local time and UTC visible.
		date_default_timezone_set ('Europe/Prague');
	}

	public static function tearDownAfterClass () : void
	{
		setConfigVar ('DATETIME_FORMAT', self::$old_format);
		date_default_timezone_set (self::$old_tz);
	}

	/**
	 * @group small
	 * @dataProvider providerRoundTrip
	 */
	public function testRoundTrip ($format, $str, $year, $month, $day, $hour, $minute, $second)
	{
		setConfigVar ('DATETIME_FORMAT', $format);
		$ts = mktime ($hour, $minute, $second, $month, $day, $year);
		$this->assertSame ($ts, timestampFromDatetimestr ($str));
		$this->assertSame ($str, datetimestrFromTimestamp ($ts));
	}

	/**
	 * @group small
	 * @dataProvider providerParseOnly
	 */
	public function testParseOnly ($format, $str, $year, $month, $day, $hour, $minute, $second)
	{
		setConfigVar ('DATETIME_FORMAT', $format);
		$ts = mktime ($hour, $minute, $second, $month, $day, $year);
		$this->assertSame ($ts, timestampFromDatetimestr ($str));
	}

	/**
	 * @group small
	 * @dataProvider providerParseIAE
	 */
	public function testParseIAE ($format, $str)
	{
		setConfigVar ('DATETIME_FORMAT', $format);
		$this->expectException (InvalidArgException::class);
		timestampFromDatetimestr ($str);
	}

	/**
	 * @group small
	 * @dataProvider providerUnsupportedFormat
	 */
	public function testUnsupportedFormatParse ($format)
	{
		setConfigVar ('DATETIME_FORMAT', $format);
		$this->expectException (InvalidArgException::class);
		timestampFromDatetimestr ('2001-02-03');
	}

	// Output works for any format, including the ones that cannot be
	// parsed, the same way as strftime() of glibc did.
	/**
	 * @group small
	 * @dataProvider providerOutput
	 */
	public function testOutput ($format, $winter, $summer)
	{
		setConfigVar ('DATETIME_FORMAT', $format);
		$this->assertSame ($winter, datetimestrFromTimestamp (mktime (4, 5, 6, 2, 3, 2001)));
		$this->assertSame ($summer, datetimestrFromTimestamp (mktime (16, 5, 6, 7, 4, 2021)));
	}

	/**
	 * @group small
	 * @dataProvider providerUnsupportedFormat
	 */
	public function testUnsupportedFormatSQLDate ($format)
	{
		$this->expectException (InvalidArgException::class);
		SQLDateFromDateStr ('2001-02-03', $format);
	}

	public function providerRoundTrip ()
	{
		return array
		(
			// the default format
			array ('%Y-%m-%d', '2017-01-01', 2017, 1, 1, 0, 0, 0),
			array ('%Y-%m-%d', '2024-02-29', 2024, 2, 29, 0, 0, 0),
			array ('%Y-%m-%d', '2021-03-28', 2021, 3, 28, 0, 0, 0),
			array ('%Y-%m-%d', '2021-10-31', 2021, 10, 31, 0, 0, 0),
			array ('%Y-%m-%d', '1970-01-02', 1970, 1, 2, 0, 0, 0),
			array ('%Y-%m-%d', '2106-02-06', 2106, 2, 6, 0, 0, 0),
			// other date-only formats
			array ('%d.%m.%Y', '08.07.1983', 1983, 7, 8, 0, 0, 0),
			array ('%d/%m/%y', '11/12/13', 2013, 12, 11, 0, 0, 0),
			array ('%m/%d/%Y', '12/31/1999', 1999, 12, 31, 0, 0, 0),
			array ('%Y%m%d', '20010203', 2001, 2, 3, 0, 0, 0),
			array ('%-d.%-m.%Y', '3.2.2001', 2001, 2, 3, 0, 0, 0),
			array ('%e.%m.%Y', ' 3.02.2001', 2001, 2, 3, 0, 0, 0),
			array ('%d %b %Y', '03 Feb 2001', 2001, 2, 3, 0, 0, 0),
			array ('%d-%h-%Y', '03-Feb-2001', 2001, 2, 3, 0, 0, 0),
			array ('%B %e, %Y', 'February  3, 2001', 2001, 2, 3, 0, 0, 0),
			array ('%a %d %b %Y', 'Sat 03 Feb 2001', 2001, 2, 3, 0, 0, 0),
			array ('%A, %d %B %Y', 'Saturday, 03 February 2001', 2001, 2, 3, 0, 0, 0),
			array ('%D', '02/03/01', 2001, 2, 3, 0, 0, 0),
			array ('%F', '2001-02-03', 2001, 2, 3, 0, 0, 0),
			array ('%x', '02/03/01', 2001, 2, 3, 0, 0, 0),
			array ('%Y-%m-%d%%', '2001-02-03%', 2001, 2, 3, 0, 0, 0),
			// date and time
			array ('%Y-%m-%d %H:%M:%S', '2021-10-31 01:59:59', 2021, 10, 31, 1, 59, 59),
			array ('%Y-%m-%d %H:%M', '2021-07-04 23:59', 2021, 7, 4, 23, 59, 0),
			array ('%F %T', '2001-02-03 04:05:06', 2001, 2, 3, 4, 5, 6),
			array ('%F %R', '2001-02-03 14:05', 2001, 2, 3, 14, 5, 0),
			array ('%x %X', '02/03/01 04:05:06', 2001, 2, 3, 4, 5, 6),
			array ('%c', 'Sat Feb  3 04:05:06 2001', 2001, 2, 3, 4, 5, 6),
			array ('%r %F', '04:05:06 PM 2001-02-03', 2001, 2, 3, 16, 5, 6),
			array ('%I:%M %p %F', '12:00 AM 2001-02-03', 2001, 2, 3, 0, 0, 0),
			array ('%I:%M %p %F', '12:30 PM 2001-02-03', 2001, 2, 3, 12, 30, 0),
			array ('%I:%M %p %F', '01:05 PM 2001-02-03', 2001, 2, 3, 13, 5, 0),
			array ('%k:%M %F', ' 4:05 2001-02-03', 2001, 2, 3, 4, 5, 0),
			array ('%l:%M %p %F', ' 4:05 PM 2001-02-03', 2001, 2, 3, 16, 5, 0),
			array ('%-H:%M %F', '4:05 2001-02-03', 2001, 2, 3, 4, 5, 0),
			array ("%F%n%T", "2001-02-03\n04:05:06", 2001, 2, 3, 4, 5, 6),
			array ("%F%t%T", "2001-02-03\t04:05:06", 2001, 2, 3, 4, 5, 6),
			// specifiers that parsing skips or validates
			array ('%d.%m.%Y %H:%M %Z', '03.02.2001 04:05 CET', 2001, 2, 3, 4, 5, 0),
			array ('%d.%m.%Y %H:%M %Z', '04.07.2021 16:05 CEST', 2021, 7, 4, 16, 5, 0),
			array ('%F %T %z', '2001-02-03 04:05:06 +0100', 2001, 2, 3, 4, 5, 6),
			array ('%a %u %w %F', 'Sat 6 6 2001-02-03', 2001, 2, 3, 0, 0, 0),
			array ('%G-W%V %g %F', '2001-W05 01 2001-02-03', 2001, 2, 3, 0, 0, 0),
			// flags and field widths
			array ('%_d.%m.%Y', ' 3.02.2001', 2001, 2, 3, 0, 0, 0),
			array ('%0e.%-m.%Y', '03.2.2001', 2001, 2, 3, 0, 0, 0),
			array ('%d-%^b-%Y', '03-FEB-2001', 2001, 2, 3, 0, 0, 0),
			array ('%#A %d %#B %Y', 'SATURDAY 03 FEBRUARY 2001', 2001, 2, 3, 0, 0, 0),
			array ('%_10Y-%m-%d', '      2001-02-03', 2001, 2, 3, 0, 0, 0),
			// the day of the year and the century
			array ('%Y %j', '2001 034', 2001, 2, 3, 0, 0, 0),
			array ('%Y %j', '2004 366', 2004, 12, 31, 0, 0, 0),
			array ('%C%y-%m-%d', '2001-02-03', 2001, 2, 3, 0, 0, 0),
		);
	}

	// Input that is valid but not in the canonical form, the same as
	// strptime() used to accept it.
	public function providerParseOnly ()
	{
		return array
		(
			array ('%Y-%m-%d', '2017-1-1', 2017, 1, 1, 0, 0, 0),
			array ('%Y-%m-%d', ' 2017- 1- 1', 2017, 1, 1, 0, 0, 0),
			array ('%Y-%m-%d', '2017-01-01 12:34', 2017, 1, 1, 0, 0, 0),
			array ('%Y %m %d', "2017 \t 01\n01", 2017, 1, 1, 0, 0, 0),
			array ('%d %b %Y', '3 FEB 2001', 2001, 2, 3, 0, 0, 0),
			array ('%d %b %Y', '3 february 2001', 2001, 2, 3, 0, 0, 0),
			array ('%d %B %Y', '3 feb 2001', 2001, 2, 3, 0, 0, 0),
			array ('%a %d.%m.%Y', 'monday 03.02.2001', 2001, 2, 3, 0, 0, 0),
			array ('%d.%m.%y', '2.1.70', 1970, 1, 2, 0, 0, 0),
			array ('%d.%m.%y', '31.12.68', 2068, 12, 31, 0, 0, 0),
			array ('%I:%M %p %F', '1:05 pm 2001-02-03', 2001, 2, 3, 13, 5, 0),
			array ('%H:%M %p %F', '10:05 PM 2001-02-03', 2001, 2, 3, 10, 5, 0),
			array ('%Y%m%d', '2001023', 2001, 2, 3, 0, 0, 0),
			array ('%Y-%m-%d %Z', '2001-02-03 anything', 2001, 2, 3, 0, 0, 0),
			array ('%Y-%m-%d %Z', '2001-02-03', 2001, 2, 3, 0, 0, 0),
			array ('%Z%Y', 'CET 2001', 2001, 1, 0, 0, 0, 0),
			array ('%Y%Z', '2001+0545', 2001, 1, 0, 0, 0, 0),
			array ('%F %z', '2001-02-03 Z', 2001, 2, 3, 0, 0, 0),
			array ('%F %z', '2001-02-03 -05:30', 2001, 2, 3, 0, 0, 0),
			array ('%F %z', '2001-02-03 +01', 2001, 2, 3, 0, 0, 0),
			array ('%j %Y', '60 2004', 2004, 2, 29, 0, 0, 0),
			array ('%j %Y', '60 2001', 2001, 3, 1, 0, 0, 0),
			// day 366 of a non-leap year, computed as strptime() did
			array ('%Y %j', '2001 366', 2003, 1, 31, 0, 0, 0),
			// the weekday makes strptime() ignore the day of the year
			array ('%u %Y %j', '1 2001 034', 2000, 12, 31, 0, 0, 0),
			array ('%y %C', '01 20', 2001, 1, 0, 0, 0, 0),
		);
	}

	public function providerParseIAE ()
	{
		return array
		(
			array ('%Y-%m-%d', ''),
			array ('%Y-%m-%d', 'abcdef'),
			array ('%Y-%m-%d', '12/21/2000'),
			array ('%Y-%m-%d', '20.05.1973'),
			array ('%Y-%m-%d', '2001-13-01'),
			array ('%Y-%m-%d', '2001-00-10'),
			array ('%Y-%m-%d', '2001-01-32'),
			array ('%Y-%m-%d', '2001-01-00'),
			array ('%Y-%m-%d', '2001-01-'),
			array ('%Y-%m-%d', '2001 -01-01'),
			array ('%Y-%m-%d', '+2001-01-01'),
			array ('%Y-%m-%d', '-2001-01-01'),
			array ('%Y-%m-%d', '1969-12-31'),
			array ('%Y-%m-%d', '2106-02-08'),
			array ('%d.%m.%Y', '45.1.2000'),
			array ('%d %b %Y', '3 Fbr 2001'),
			array ('%a %d %b %Y', 'Sut 03 Feb 2001'),
			array ('%I:%M %p %F', '13:00 PM 2001-02-03'),
			array ('%I:%M %p %F', '12:00 XM 2001-02-03'),
			array ('%H:%M %F', '24:00 2001-02-03'),
			array ('%H:%M %F', '23:60 2001-02-03'),
			array ('%F %z', '2001-02-03 +0160'),
			array ('%F %z', '2001-02-03 +1'),
			array ('%F %z', '2001-02-03 0100'),
			array ('%Y %j', '2001 367'),
			array ('%Y %j', '2001 0'),
			// the zero padding of %10Y is read as the year 0000
			array ('%10Y-%m-%d', '0000002001-02-03'),
			// glibc strptime() reads a %Z time zone name up to the next
			// white-space, so a literal or a field right after %Z never
			// matches (output with such a format cannot be parsed back).
			array ('%Z-%Y', 'CET-2001'),
			array ('%Z%Y', 'CET2001'),
			array ('%Z,%Y', 'CET,2001'),
			array ('%Z/%d/%m/%Y', 'UTC/03/02/2001'),
			array ('(%Z) %Y-%m-%d', '(CET) 2001-02-03'),
			array ('%G %F', 'x 2001-02-03'),
			array ('%u %F', '8 2001-02-03'),
			array ('%w %F', '7 2001-02-03'),
		);
	}

	// Formats that parsing does not support, as strptime() either did not
	// support them or computed the result in a way that cannot be repeated.
	public function providerUnsupportedFormat ()
	{
		return array
		(
			array ('%Q'),
			array ('%s'),
			array ('%P %F'),
			array ('%U %w %Y'),
			array ('%W %u %Y'),
			array ('%Ey-%m-%d'),
			array ('%Od.%m.%Y'),
			array ('%Y-%m-%d%'),
		);
	}

	public function providerOutput ()
	{
		return array
		(
			array ('%Y-%m-%d', '2001-02-03', '2021-07-04'),
			array ('%d.%m.%Y %H:%M %Z', '03.02.2001 04:05 CET', '04.07.2021 16:05 CEST'),
			array ('%_d.%m.%Y', ' 3.02.2001', ' 4.07.2021'),
			array ('%0e.%-m.%Y', '03.2.2001', '04.7.2021'),
			array ('%j', '034', '185'),
			array ('%u %w', '6 6', '7 0'),
			array ('%U %W', '04 05', '27 26'),
			array ('%V %G %g', '05 2001 01', '26 2021 21'),
			array ('%C', '20', '20'),
			array ('%z', '+0100', '+0200'),
			array ('%^b %#B %^a %#A %#p %P %#Z', 'FEB FEBRUARY SAT SATURDAY am am cet', 'JUL JULY SUN SUNDAY pm pm cest'),
			array ('%10Y|%-10d|%_5m|%05e', '0000002001|         3|    2|00003', '0000002021|         4|    7|00004'),
			array ('%Ey %EY %Od %OH', '01 2001 03 04', '21 2021 04 16'),
			array ('%^c', 'SAT FEB  3 04:05:06 2001', 'SUN JUL  4 16:05:06 2021'),
			array ('%10F', '2001-02-03', '2021-07-04'),
			// unknown or invalid conversions are copied as they are
			array ('%Q %+ %Ed %OY', '%Q %+ %Ed %OY', '%Q %+ %Ed %OY'),
			array ('%Y-%m-%d%', '2001-02-03%', '2021-07-04%'),
			// glibc computed %s in the time zone of the process
			array ('%s', '%s', '%s'),
			// too long for the strftime() buffer of PHP
			array ('%4096Y', '', ''),
		);
	}
}
?>
