<?php

// In strict mode, only a variable of exact type of the type
// declaration will be accepted, or a TypeError will be thrown.

//declare(strict_types=1);

namespace App\Model\Reservation;

use Latte;
use Nette;
use App\Model;
use App\Model\Reservation;
use App\Model\Dotykacka;
use App\Model\Ecomail;
use App\Model\SmsBrana;
use App\Model\Reviews;

use Nette\Utils\Json;
use Nette\Utils\Random;
use Nette\Utils\ArrayHash;
use Nette\Utils\Validators;
use Nette\Database\Context;
use Tracy\Debugger;

// DATE / TIME
use Carbon\Carbon;

// SEND MAIL
use Nette\Mail;
use Nette\Application\UI\ITemplateFactory;


class Calendar extends Reservation
{
	/** @var Nette\Database\Context */
	protected $database;

	/** @var Dotykacka\DotykackaApi2 */
	private $doty2;

	/** @var Model\SmsBrana\SmsBrana */
	protected $smsbrana;

	/** @var Ecomail\EcomailApi */
	protected $ecomail;

	/** @var Model\Reviews\Reviews */
	protected $reviews;


	public function __construct(Context $database,
								Dotykacka\DotykackaApi2 $doty2,
								SmsBrana\SmsBrana $smsbrana,
								Reviews\Reviews $reviews)
	{
		$this->database = $database;
		$this->doty2 = $doty2;
		$this->smsbrana = $smsbrana;
		$this->reviews = $reviews;
		$this->ecomail = new Ecomail\EcomailApi();
	}


	/* ####################################### ARRAYS ####################################### */

	protected $monthNamesShort = [1 => "Led", 2 => "Úno", 3 => "Bře", 4 => "Dub", 5 => "Kvě", 6 => "Čer", 7 => "Čvc", 8 => "Srp", 9 => "Zář", 10 => "Říj", 11 => "Lis", 12 => "Pro"];
	protected $monthNamesFull = [1 => "Leden", 2 => "Únor", 3 => "Březen", 4 => "Duben", 5 => "Květen", 6 => "Červen", 7 => "Červenec", 8 => "Srpen", 9 => "Září", 10 => "Říjen", 11 => "Listopad", 12 => "Prosinec"];
	protected $monthNamesOrdinal = [1 => "Ledna", 2 => "Února", 3 => "Března", 4 => "Dubna", 5 => "Května", 6 => "Června", 7 => "Července", 8 => "Srpna", 9 => "Září", 10 => "Října", 11 => "Listopadu", 12 => "Prosince"];
	protected $dayNamesShort = [0 => "NE", 1 => "PO", 2 => "ÚT", 3 => "ST", 4 => "ČT", 5 => "PÁ", 6 => "SO", 7 => "NE"];
	protected $dayNamesFull = [0 => "Neděle", 1 => "Pondělí", 2 => "Úterý", 3 => "Středa", 4 => "Čtvrtek", 5 => "Pátek", 6 => "Sobota", 7 => "Neděle"];

	/* ##################################### TRANSLATOR ##################################### */

	/** Translate MONTH Name / SHORT
	 * @param	integer		$month
	 * @return	string
	 */
	public function getMonthNameShort($month)
	{
		return (Validators::is($month, 'numericint:1..12') ? $this->monthNamesShort[$month] : "UNK");
	}

	/** Translate MONTH Name / FULL
	 * @param	integer		$month
	 * @return	string
	 */
	public function getMonthNameFull($month)
	{
		return (Validators::is($month, 'numericint:1..12') ? $this->monthNamesFull[$month] : "UNKNOWN");
	}

	/** Translate MONTH Name / ORDINAL
	 * @param	integer		$month
	 * @return	string
	 */
	public function getMonthNameOrdinal($month)
	{
		return (Validators::is($month, 'numericint:1..12') ? $this->monthNamesOrdinal[$month] : "UNKNOWN");
	}

	/** Translate DAY Name / SHORT
	 * @param	integer		$day
	 * @return	string
	 */
	public function getDayNameShort($day)
	{
		return (Validators::is($month, 'numericint:0..7') ? $this->dayNamesShort[$day] : "UNK");
	}

	/** Translate DAY Name / FULL
	 * @param	integer		$day
	 * @return	string
	 */
	public function getDayNameFull($day)
	{
		return (Validators::is($month, 'numericint:0..7') ? $this->dayNamesFull[$day] : "UNKNOWN");
	}

	/* ###################################################################################### */

	/** Get Today Date as Array
	 * @param	void
	 * 
	 * @return	array		$dateNow['year','month','day','hour','minute','second']
	 */
	public function getDateNowArray()
	{
		$now = Carbon::now();
		$dateNow = [
			'year'		=> $now->year,		// Rok
			'month'		=> $now->month,		// Mesic
			'day'		=> $now->day,		// Den
			'hour'		=> $now->hour,		// Hodina
			'minute'	=> $now->minute,	// Minuta
			'second'	=> $now->second,	// Vterina
		];
		return $dateNow;
	}

	/** Generate Random AuthCode (SMS)
	 * @param	integer			$size		// Delka kodu
	 * @param	integer			$limit		// Maximalni pocet opakovani
	 *
	 * @return	string
	 */
	public function getRandomAuthCode($size = 4, $limit = 10)
	{
		$errorCode = str_repeat('9', $size);

		for($i = 0; $i < $limit; $i++)
		{
			$randomCode = Random::generate($size, '0-9');
			if($randomCode == $errorCode)
			{
				//$i--;
				continue;
			}

			$result = $this->database->query('SELECT * FROM reservation_request WHERE created > NOW() - INTERVAL 15 MINUTE AND status = ? AND authCode = ? LIMIT 1', 'NEW', $randomCode);
			if(!isset($result) || $result->getRowCount() == 0)
			{
				return (string)$randomCode;
			}
		}

		return $errorCode;
	}

	/* ###################################################################################### */
	
	/** Color Converter: HSL to RGB
	 * @param	integer			$h	(hue)
	 * @param	integer			$s	(saturation)
	 * @param	integer			$l	(luminance)
	 * 
	 * @return	array			$rgbColor['r', 'g', 'b']
	 */
	private function ColorHSL2RGB($h, $s, $l){
		$r = $l;
		$g = $l;
		$b = $l;
		$v = ($l <= 0.5) ? ($l * (1.0 + $s)) : ($l + $s - $l * $s);
		if ($v > 0){
			$m = $l + $l - $v;
			$sv = ($v - $m ) / $v;
			$h *= 6.0;
			$sextant = floor($h);
			$fract = $h - $sextant;
			$vsf = $v * $sv * $fract;
			$mid1 = $m + $vsf;
			$mid2 = $v - $vsf;

			switch ($sextant)
			{
				case 0: $r = $v;	$g = $mid1;	$b = $m;	break;
				case 1: $r = $mid2;	$g = $v;	$b = $m;	break;
				case 2: $r = $m;	$g = $v;	$b = $mid1;	break;
				case 3: $r = $m;	$g = $mid2;	$b = $v;	break;
				case 4: $r = $mid1;	$g = $m;	$b = $v;	break;
				case 5: $r = $v;	$g = $m;	$b = $mid2;	break;
			}
		}
		return array('r' => $r * 255.0, 'g' => $g * 255.0, 'b' => $b * 255.0);
	}

	/** Get Color by Percentil
	 * @param	integer			$value		(value)
	 * @param	integer			$min		(low treshold)
	 * @param	integer			$max		(high treshold)
	 * 
	 * @return	string			$hexColor	(#123456)
	 */
	private function getColorByPercentil($value, $min = 0, $max = .5)
	{
		$ratio = $value;
		if ($min > 0 || $max < 1) {
			if ($value < $min) {
				$ratio = 1;
			} else if ($value > $max) {
				$ratio = 0;
			} else {
				$range = $min - $max;
				$ratio = ($value - $max) / $range;
			}
		}

		$hue = ($ratio * 1.2) / 3.60;
		$rgb = $this->ColorHSL2RGB($hue, 1, .5);

		return (string)sprintf("#%02X%02X%02X", (int)round($rgb['r'], 0), (int)round($rgb['g'], 0), (int)round($rgb['b'], 0));
	}

	/* ###################################################################################### */
	/*  DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA DATA  */
	/* ###################################################################################### */

	/** Get RenderData for SELECT-DAY
	 * @param	integer			$year
	 * @param	integer			$month
	 * 
	 * @return	array|bool		$renderData[...]
	 */
	public function getRenderData_Selectday($year, $month)
	{
		if (!Validators::is($year, 'numericint:' . (Carbon::now()->year + 0) . '..' . (Carbon::now()->year + 1)) ||
			!Validators::is($month, 'numericint:1..12') || strlen((string)$year) != 4 || strlen((string)$month) > 2)
		{
			return false;
		}

		$carbon = new Carbon;
		$carbon->setDateTime($year, $month, 1, 0, 0, 0);
		$calendar = $carbon->subDays($carbon->dayOfWeek == 0 ? 6 : $carbon->dayOfWeek - 1);
		$dateYesterday = Carbon::now()->subDay();

		$pagination = [
			'year'		=> (int)$year,
			'yearPrev'	=> Carbon::now()->setDateTime($year, $month, 1, 0, 0, 0)->subMonth()->year,
			'yearNext'	=> Carbon::now()->setDateTime($year, $month, 1, 0, 0, 0)->addMonth()->year,
			'month'		=> (int)$month,
			'monthPrev'	=> Carbon::now()->setDateTime($year, $month, 1, 0, 0, 0)->subMonth()->month,
			'monthNext'	=> Carbon::now()->setDateTime($year, $month, 1, 0, 0, 0)->addMonth()->month,
		];

		$calMonthPage = [];
		while (($calendar->year  != $pagination['yearNext'] ||
				$calendar->month != $pagination['monthNext']) ||
				$calendar->dayOfWeek != 1)
		{
			$unitsCount = $this->getUnitsCount($calendar->year, $calendar->month, $calendar->day);
			$disabled = ($calendar->year <= $dateYesterday->year && $calendar->month <= $dateYesterday->month && $calendar->day <= $dateYesterday->day);
			$isToday = (Carbon::now()->year == $calendar->year && Carbon::now()->month == $calendar->month && Carbon::now()->day == $calendar->day);
			$bgColor = $this->getColorByPercentil($unitsCount['free'] / $unitsCount['total'], 0, 1);

			if($disabled) // RESERVATION_FAKE
			{
				$randomFree = (int)rand(0, $unitsCount['total'] - 8);
				$dbDateString = $calendar->year."-".$calendar->month."-".$calendar->day;

				$result = $this->database->query('SELECT * FROM reservation_fake WHERE date = ? LIMIT 1', $dbDateString);
				if($result && $result->getRowCount() == 1)
					$randomFree = (int)$result->fetch()['randomFree'];
				else
					$this->database->query('REPLACE INTO reservation_fake', ['date' => $dbDateString, 'randomFree' => $randomFree]);

				$bgColor = $this->getColorByPercentil($randomFree / $unitsCount['total'], 0, 1);
			}
			else if($isToday) // TODAY - BLUE MARK
			{
				$bgColor = "#007BFF";
			}

			$calMonthPage[] = [
				'y'			=> $calendar->year,
				'm'			=> $calendar->month,
				'd'			=> $calendar->day,
				'url'		=> $calendar->year."/".$calendar->month."/".$calendar->day,
				'dow'		=> $calendar->dayOfWeek,
				'skip'		=> ($calendar->month == $pagination['monthPrev'] || $calendar->month == $pagination['monthNext']),
				'today'		=> $isToday,
				//'class'	=> ($calendar->dayOfWeek == 0 ? " red" : "").($calendar->month != $month ? " gray" : ""),
				'class'		=> "",
				'disabled'	=> $disabled,
				//'bkg_col'	=> ($disabled ? "#ccc" : $colGenOc),
				'bkg_col'	=> $bgColor,
			];
			$calendar->addDay();
		}

		// COLOR PALETTE (Legend)
		$palette = [];
		$seatsTotal = $this->getUnitsCountTotal();
		for($p = $seatsTotal; $p >= 0; $p--)
		{
			$palette[] = $this->getColorByPercentil($p / $seatsTotal, 0, 1);
		}

		$renderData = [
			//'now'			=> $this->getDateNowArray(),
			'pagination'	=> $pagination,
			'monthName'		=> $this->getMonthNameFull($month),
			'dayNamesShort'	=> $this->dayNamesShort,
			'calMonthPage'	=> $calMonthPage,
			'palette'		=> $palette,

			// DEBUG: DUMMY
			'debug'			=> "N/A",
		//	'debug'			=> $this->_RENDER_DEBUG_DATA_(),
		];

		return $renderData;
	}

	/* ###################################################################################### */

	/** Get RenderData for SELECT-HOUR
	 * @param	integer			$year
	 * @param	integer			$month
	 * @param	integer			$day
	 * 
	 * @return	array|bool		$renderData[...]
	 */
	public function getRenderData_Selecthour($year, $month, $day)
	{
		if (!Validators::is($year, 'numericint:' . (Carbon::now()->year + 0) . '..' . (Carbon::now()->year + 1)) ||
			!Validators::is($month, 'numericint:1..12') ||
			!Validators::is($day, 'numericint:1..' . Carbon::create($year, $month, 1)->daysInMonth) ||
			strlen((string)$year) != 4 || strlen((string)$month) > 2 || strlen((string)$day) > 2)
		{
			return false;
		}

		// RESYNC Reservations
		$this->syncReservationsByDay($year, $month, $day);

		$renderData = [
			'monthName'		=> $this->getMonthNameOrdinal($month),
			//'dayName'		=> $this->getDayNameFull($day),
			'occupancyData'	=> $this->renderOccupancy($year, $month, $day),

			// DEBUG: DUMMY
			'debug'			=> "N/A",
		];

		return $renderData;
	}

	/* ###################################################################################### */
	/* ##################################### UNITS DATA ##################################### */
	/* ###################################################################################### */

	/** Get UNITS Data
	 * @return	Object|NULL		$unitsData[...]
	 */
	public function getUnitsData()
	{
		$result = $this->database->query('SELECT * FROM units ORDER BY hour ASC, unitID ASC');
		return ($result && $result->getRowCount() > 0) ? $result->fetchAll() : NULL;
	}

	/** Get UNITS Count - Total
	 * @return	integer			$unitsTotal
	 */
	public function getUnitsCountTotal()
	{
		$units = $this->getUnitsData();
		return ($units ? count($units) : 0);
	}

	/** Get UNITS Counter - Array
	 * @param	integer			$year
	 * @param	integer			$month
	 * @param	integer			$day
	 * 
	 * @return	array			$unitsCount ['total','free','occupied','error']
	 */
	public function getUnitsCount($year, $month, $day)
	{
		$total = $this->getUnitsCountTotal();
		$free = 0;
		$occupied = 0;

		foreach($this->getOccupancy($year, $month, $day) as /*$unitName =>*/ $value)
		{
			switch($value)
			{
				case 0: $free++; break;
				case 1: $occupied++; break;
			}
		}

		return [
			'total'		=> $total,
			'free'		=> $free,
			'occupied'	=> $occupied,
			'error'		=> ($total != $free + $occupied),
		];
	}

	/** Return TRUE if given UNIT is Enabled (reservable)
	 * @param	string			$unit		// Unit string (eg. '16A')
	 * 
	 * @return	bool			$result
	 */
	public function isUnitEnabled(string $unit)
	{
		if(strlen($unit) !== 3)
			return false;

		$alphabet = range('A', 'Z');
		foreach($this->getUnitsData() as $item)
		{
			$unitName = $item->hour . $alphabet[$item->unitID];
			if($unit === $unitName)
				return true;
		}
		return false;
	}

	/** Get Dotykacka TableID by Reservation UnitName
	 * @param	string			$unit		// Unit string (eg. '16A')
	 * 
	 * @return	string|NULL		$result
	 */
	public function getTableIdByUnitName(string $unit)
	{
		if(strlen($unit) !== 3)
			return NULL;

		$alphabet = range('A', 'Z');
		$tables = $this->doty2->getTables();
		foreach($this->getUnitsData() as $item)
		{
			$unitName = $item->hour . $alphabet[$item->unitID];
			if($unit === $unitName && $item->unitID < count($tables))
				return $tables[$item->unitID];
		}
		return NULL;
	}

	/** GET Reservation First Hour as integer
	 * @param	string			$units		// Units string (eg. '16A')
	 * 
	 * @return	integer			$result
	 */
	public function getReservationFirstHour($units)
	{
		$result = 24; // ERROR = 24 (hour)
		foreach(json_decode($units) as $item)
		{
			$hour = (int)substr($item, 0, 2);
			if($hour < $result)
				$result = $hour;
		}
		return (int)$result;
	}

	/* ###################################################################################### */
	/* ################################### OCCUPANCY DATA ################################### */
	/* ###################################################################################### */

	/** RENDER Occupancy data for day
	 * @param	integer			$year
	 * @param	integer			$month
	 * @param	integer			$day
	 * 
	 * @return	array|NULL		$units
	 */
	public function renderOccupancy($year, $month, $day)
	{
		$units = array();
		$date = (string)sprintf("%4d-%02d-%02d", (int)$year, (int)$month, (int)$day);

		// Time machine prevention
		$hourLimit = Carbon::now('Europe/Prague')->addMinute(5)->hour + 1;
		$dateRes = Carbon::create((int)$year, (int)$month, (int)$day)->setTimezone('Europe/Prague')->startOfDay();
		$dateNow = Carbon::now('Europe/Prague')->startOfDay();
		$today = ($dateNow == $dateRes) ? true : false;

		// Units array prepare
		$alphabet = range('A', 'Z');
		foreach($this->getUnitsData() as $item)
		{
			$unitName = $item->hour . $alphabet[$item->unitID];
			$units[$unitName] = ($today && $item->hour < $hourLimit) ? 1 : 0;
		}
		if(empty($units))
			return NULL;

		// Get all reservations for day
		//$resultR = $this->database->query('SELECT units FROM reservation WHERE date = ? AND (state = ? OR state = ?) ORDER BY id ASC', $date, 'new', 'active');
		$resultR = $this->database->query('SELECT units FROM reservation WHERE date = ? LIMIT 1', $date);
		if($resultR && $resultR->getRowCount() === 1)
		{
			foreach($resultR->fetchAll() as $unitString)
			{
				foreach(json_decode($unitString->units) as $unitName)
				{
					$units[$unitName] = 1;
				}
			}
		}

		// Update occupancy fields
		$resultO = $this->database->query('REPLACE INTO occupancy', [
			'date' => $date,
			'occupancyData' => json_encode($units),
		]);

		return $units;
	}

	/** GET Occupancy data for day
	 * @param	integer			$year
	 * @param	integer			$month
	 * @param	integer			$day
	 * 
	 * @return	array|NULL		$units
	 */
	public function getOccupancy($year, $month, $day)
	{
		$units = array();
		$date = (string)sprintf("%4d-%02d-%02d", (int)$year, (int)$month, (int)$day);

		// Get occupancy fields
		$result = $this->database->query('SELECT occupancyData FROM occupancy WHERE date = ?', $date);
		if($result && $result->getRowCount() > 0)
			return json_decode($result->fetch()->occupancyData);
		else
			return $this->renderOccupancy($year, $month, $day);
	}

	/* ###################################################################################### */
	/* ############################### RESERVATION SLOTS DATA ############################### */
	/* ###################################################################################### */

	public function _RENDER_DEBUG_DATA_()
	{
		return "N/A";

		// CHECK RESERVATION SLOT
		//return $this->checkReservationSlot($this->doty2->getTables()[0], Carbon::create(2021, 04, 16, 5, 0, 0));

		// PREPARE RESERVATION SLOT
		//return $this->prepareReservationSlot(123456, 7890123, 2021, 4, 18, 3, 'Poznamka');

		// PREPARE RESERVATIONS
		//return $this->prepareReservations();

		// SYNC RESERVATIONS
		//return $this->syncReservationsByDay(2021, 4, 18);

		// RESERVATION REQUEST (RAW)
		//return $this->createReservationRequest_raw(2021, 4, 19, '["20B","21B","19C"]', 'Prymoš', 'Roglič', 'email@example.com', '+420123456789', false);

		// RESERVATION COMPLETE
		//return $this->completeReservationRequest('0910');

		// GET CUSTOMER LIST
		//return $this->doty2->getCustomerList();

		// GET RESERVATION LIST
		//return $this->doty2->getReservationList();

		// TRY SEND SMS
		//return $this->smsbrana->sendSMS("+420736168785", "Toto je testovaci zprava SMS.");

		// RESERVATION UNITS FIRST HOUR
		//return $this->getReservationFirstHour('["18A","14A","20A"]');

		//return \base64_decode("CiAgICA8c3R5bGU+CiAgICAgICAgcCB7IG1hcmdpbi10b3A6IDI4cHg7IH0KICAgICAgICBhIHsgY29sb3I6IzE1YzsgfQogICAgPC9zdHlsZT4KCgo8ZGl2IHN0eWxlPSJ3aWR0aDogMTAwJTsgYmFja2dyb3VuZC1jb2xvcjogI2Y0ZjRmNDsiPgogICAgPGRpdiBzdHlsZT0iY29sb3I6ICMwMDAwMDA7IGZvbnQtZmFtaWx5OiBIZWx2ZXRpY2E7IGZvbnQtc2l6ZTogMTZweDsgbGluZS1oZWlnaHQ6IDI1cHg7IG1hcmdpbjogMCBhdXRvOyBtYXgtd2lkdGg6IDYwMHB4OyI+CiAgICAgICAgPGRpdiBzdHlsZT0icGFkZGluZzogMTVweCAyNXB4IDEwcHggMjVweDsiPgogICAgICAgICAgICA8aW1nIHNyYz0iaHR0cHM6Ly9wYXltZW50cy5jb21nYXRlLmN6L2Fzc2V0cy9pbWFnZXMvY2dsb2dvcHMxMTUucG5nIiBhbHQ9ImxvZ28gQ29tR2F0ZSIgdGl0bGU9ImxvZ28gQ29tR2F0ZSIvPgogICAgICAgIDwvZGl2PgogICAgICAgIDxkaXYgc3R5bGU9ImJhY2tncm91bmQtY29sb3I6ICNmZmY7IHBhZGRpbmc6IDI1cHg7Ij4KCiAgICAgICAgICAgIDxwIHN0eWxlPSJtYXJnaW4tdG9wOjBweDsiPgogICAgICAgICAgICAgICAgWiBvYmNob2R1IDxhIGhyZWY9Imh0dHA6Ly93d3cuVlJrby5jeiI+d3d3LlZSa28uY3o8L2E+IGpzbWUgcMWZaWphbGkgcG/FvmFkYXZlayBrIHByb3ZlZGVuw60gcGxhdGJ5PGJyIC8+dmUgdsO9xaFpIDxiPjQwMCwwMCBDWks8L2I+LgogICAgICAgICAgICA8L3A+CgogICAgICAgICAgICA8cD4KICAgICAgICAgICAgICAgIDx0YWJsZT4KICAgICAgICAgICAgICAgICAgICA8dGJvZHk+CiAgICAgICAgICAgICAgICAgICAgICAgIDx0cj4KICAgICAgICAgICAgICAgICAgICAgICAgICAgIDx0ZCBzdHlsZT0icGFkZGluZzogMTBweCAxNXB4OyBiYWNrZ3JvdW5kLWNvbG9yOiAjMWE3M2U4OyBib3JkZXItcmFkaXVzOiA0cHg7ICI+CiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPGEgaHJlZj0iaHR0cHM6Ly9wYXltZW50cy5jb21nYXRlLmN6L2NsaWVudC9pbnN0cnVjdGlvbnMvcGF5bWVudC1zdGF0dXMtaW5mby9pZC9CTk5BLUFXNVYtUUVZNy9oL0Z4eDFGdVlla1lWbUlrZXE5REdjM3FoTUtGc0dQYWllL3Jlc3RhcnQvMSIgc3R5bGU9ImZvbnQtd2VpZ2h0OiBib2xkOyBsZXR0ZXItc3BhY2luZzogbm9ybWFsOwogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgbGluZS1oZWlnaHQ6IDEwMCU7IHRleHQtYWxpZ246IGNlbnRlcjsKICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIHRleHQtZGVjb3JhdGlvbjogbm9uZTsgY29sb3I6ICNmZmZmZmY7Ij5aamlzdGl0IHN0YXYgcGxhdGJ5PC9hPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgPC90ZD4KICAgICAgICAgICAgICAgICAgICAgICAgPC90cj4KICAgICAgICAgICAgICAgICAgICA8L3Rib2R5PgogICAgICAgICAgICAgICAgPC90YWJsZT4KICAgICAgICAgICAgPC9wPgoKICAgICAgICAgICAgPHA+S2xpa251dMOtbSBuYSB0bGHEjcOtdGtvIG3Fr8W+ZXRlIHpqaXN0aXQgcG9kcm9ibm9zdGkgbyBzdGF2dSBwbGF0YnksIHDFmcOtcGFkbsSbIHp2b2xpdCBqaW5vdSBwbGF0ZWJuw60gbWV0b2R1LjwvcD4KCiAgICAgICAgICAgICAgICAgICAgICAgICAgICA8cD5Qb2t1ZCBqacW+IHBsYXRiYSBwcm9ixJtobGEgYSBuZW3DoXRlIGluZm9ybWFjaSBvIHZ5xZnDrXplbsOtIHZhxaHDrSBvYmplZG7DoXZreSwga29udGFrdHVqdGUgb2JjaG9kbsOta2EgbmEgPGEgaHJlZj0ibWFpbHRvOmtvemVsdWhAemV0Y29tcC5jeiI+a296ZWx1aEB6ZXRjb21wLmN6PC9hPi48L3A+CiAgICAgICAgICAgIAogICAgICAgICAgICAKICAgICAgICAgICAgPHA+SUQgcGxhdGVibsOtIHRyYW5zYWtjZTogQk5OQS1BVzVWLVFFWTc8L3A+CgogICAgICAgICAgICA8cCBzdHlsZT0ibWFyZ2luLXRvcDogYXV0bzsgbWFyZ2luLWJvdHRvbTogMDsiPlRhdG8genByw6F2YSBqZSBnZW5lcm92w6FuYSBhdXRvbWF0aWNreS4gUHJvc8OtbWUsIG5lb2Rwb3bDrWRlanRlIG5hIG5pLjwvcD4KICAgICAgICA8L2Rpdj4KICAgICAgICA8ZGl2IHN0eWxlPSJwYWRkaW5nOiAyNXB4OyBmb250LXNpemU6IDEzcHg7IGNvbG9yOiAjNzU3NTc1OyI+CiAgICAgICAgICAgIDxwIHN0eWxlPSJtYXJnaW46IDAiPkNvbUdhdGUgUGF5bWVudHMsIGEucy4sIEdvxI3DoXJvdmEgdMWZw61kYSAxNzU0IC8gNDhiLCA1MDAgMDIgSHJhZGVjIEtyw6Fsb3bDqTxiciAvPgogICAgICAgICAgICBPc29ibsOtIMO6ZGFqZSB6cHJhY292w6F2w6FtZSA8YSBocmVmPSJodHRwczovL3d3dy5jb21nYXRlLmN6L2N6L29zb2JuaS11ZGFqZSI+cG9kbGUgdMSbY2h0byBwcmF2aWRlbDwvYT48L3A+CiAgICAgICAgPC9kaXY+CiAgICA8L2Rpdj4KPC9kaXY+");
	}

	/** DOTYKACKA: Checks if Reservation SLOT is FREE?
	 * @param	integer			$_tableId		// ID Stolu
	 * @param	Carbon			$date			// Datum rezervace
	 * @param	integer			$minutes = 60	// Doba trvani rezervace (vychozi: 60 minut)
	 * 
	 * @return	bool			$isFree			// Je SLOT volny? (true: ano / false: ne)
	 */
	public function checkReservationSlot($_tableId, Carbon $date, $minutes = 60)
	{
		// Validate _tableId
		if(!in_array($_tableId, $this->doty2->getTables()))
			return false;

		// Construct DATE
		$startDate	= Carbon::create($date->year, $date->month, $date->day, $date->hour, 0, 0);
		$endDate	= Carbon::create($date->year, $date->month, $date->day, $date->hour, 0, 0)->addMinutes($minutes);

		// Build FILTER string
		$filter = "_tableId|eq|"	. $_tableId . ";"
				. "startDate|gteq|"	. $startDate->toIso8601ZuluString() . ";"
				. "startDate|lt|"	. $endDate->toIso8601ZuluString();

		// Build "SFPL" string (sort, filter, page, limit)
		$sfpl = $this->doty2->translateSFPL("", $filter, 1, 100);

		// Get result from DTK API v2
		$result = $this->doty2->getReservationList($sfpl);

		if(!empty($result))
		{
			if(empty($result->data))
				return false; // ERROR ?! CATCH THE ERROR MESSAGES [ !! HERE !! ]

			foreach($result->data as $reservation)
			{
				if($reservation->status != 'CANCELLED')
					return false; // FOUND & NOT CANCELLED == NOT FREE
			}
		}

		return true; // NOT FOUND OVER ALL == IS FREE
	}

	/** DOTYKACKA: Prepare SLOT data for the Reservation
	 * @param	integer			$_customerId	// ID Zakaznika
	 * @param	integer			$_tableId		// ID Stolu
	 * @param	integer			$year			// Rok
	 * @param	integer			$month			// Mesic
	 * @param	integer			$day			// Den
	 * @param	integer			$hour			// Hodina
	 * @param	string			$note			// Poznamka
	 * 
	 * @return	array|bool		$result			// Data pro vytvoreni polozky v Dotykacce (nejsou-li data == FALSE)
	 */
	public function prepareReservationSlot($_customerId, $_tableId, $year, $month, $day, $hour, $note = '')
	{
		// Construct DATE
		$minutes	= 60;
		$startDate	= Carbon::create($year, $month, $day, $hour, 0, 0);
		$endDate	= Carbon::create($year, $month, $day, $hour, 0, 0)->addMinutes($minutes);
		
		if($this->checkReservationSlot($_tableId, $startDate, $minutes) == false)
		{
			return false;
		}

		//ReservationSchema($tableId, $seats, $startDate, $endDate, $customerId = 0, $employeeId = 0, $note = '', $flags = 0, $status = 'CONFIRMED');
		$result = $this->doty2->ReservationSchema(
			$_tableId,							// ID Stolu
			4,									// Pocet mist (zidli)
			$startDate->toIso8601ZuluString(),	// Datum zacatku rezervace (ISO 8601 Zulu)
			$endDate->toIso8601ZuluString(),	// Datum konce rezervace (ISO 8601 Zulu)
			$_customerId,						// ID Zakaznika
			0,									// ID Zamestnance (Vychozi: 0)
			$note,								// Poznamka
			0,									// Priznaky (Vychozi: 0)
			'CONFIRMED'							// Stav rezervace [NEW, CONFIRMED, CANCELLED]
		);

		return $result;
	}

	/* ###################################################################################### */
	/* ################################## RESERVATION SYNC ################################## */
	/* ###################################################################################### */

	/** DOTYKACKA: Synchronize Reservations Database (DOTY -> DB)
	 * @param	integer			$year			// Rok
	 * @param	integer			$month			// Mesic
	 * @param	integer			$day			// Den
	 * 
	 * @return	array|bool		$result			// Pole dat s rezervacemi pro Dotykacku (nejsou-li data == FALSE)
	 */
	public function syncReservationsByDay($year, $month, $day)
	{
		$tableString = implode(",", $this->doty2->getTables());

		// Construct DATE
		$startDate	= Carbon::create($year, $month, $day, 0, 0, 0);
		$endDate	= Carbon::create($year, $month, $day, 0, 0, 0)->addDay();

		$filter = "_tableId|in|" . $tableString . ";"
				. "startDate|gteq|" . $startDate->toIso8601ZuluString() . ";"
				. "startDate|lt|" . $endDate->toIso8601ZuluString();

		$sfpl = $this->doty2->translateSFPL("startDate", $filter, 1, 100);
		$reservationList = $this->doty2->getReservationList($sfpl);

		$units = [];
		$reservations = [];
		if(!empty($reservationList))
		{
			foreach($reservationList->data as $reservation)
			{
				if($reservation->status == 'CANCELLED')
				{
					continue;
				}

				$reservations[] = (array)$reservation;
				$rsDate = Carbon::create($reservation->startDate /*, 'UTC'*/)->setTimezone('Europe/Prague');

				$tableChar = range('A', 'Z')[array_search($reservation->_tableId, $this->doty2->getTables())];
				$units[] = $rsDate->hour . $tableChar;
			}
		}

		//$unitsString = '["' . implode('","', $units) . '"]';
		$dateString = (string)sprintf("%4d-%02d-%02d", (int)$year, (int)$month, (int)$day);

		//$this->database->query('DELETE FROM reservation WHERE date = ?', $dateString);
		$resultO = $this->database->query('REPLACE INTO reservation', [
		//	'customerID'	=> 0,
			'date'			=> $dateString,
			'units'			=> json_encode($units),
		//	'state'			=> "new",
		//	'note'			=> "",
		]);

		return true; //$unitsString;
	}

	/* ###################################################################################### */
	/* ################################ RESERVATION REQUESTS ################################ */
	/* ###################################################################################### */

	/** Create Reservation request in Database (RAW)
	 * @param	integer			$year			// Datum - Rok
	 * @param	integer			$month			// Datum - Mesic
	 * @param	integer			$day			// Datum - Den
	 * @param	string			$unitsJson		// Units - JSON String s popisem rezervovanych hernich jednotek
	 * @param	string			$name			// Zakaznik - Jmeno
	 * @param	string			$surname		// Zakaznik - Prijmeni
	 * @param	string			$email			// Zakaznik - Email
	 * @param	string			$phone			// Zakaznik - Telefon
	 * @param	bool			$subscribe		// Zakaznik - Odber novinek
	 * 
	 * @return	bool|string		$result			// Uspech == TRUE / Chyba == FALSE
	 */
	public function createReservationRequest_raw($year, $month, $day, $unitsJson, $name, $surname, $email, $phone, $subscribe)
	{
		$date = Carbon::create($year, $month, $day, 0, 0, 0, "Europe/Prague");
		$units = json_decode($unitsJson);
		$customer = [
			'name'		=> $name,
			'surname'	=> $surname,
			'email'		=> $email,
			'phone'		=> $phone,
			'subscribe'	=> $subscribe,
		];
		return $this->createReservationRequest($date, $units, $customer);
	}

	/** Create Reservation request in Database (COMPLEX)
	 * @param	Carbon			$date			// Datum (staci jenom rok, mesic a den; zbytek NULL (hodina se generuje z UNITS))
	 * @param	array			$units			// Pole s popisem rezervovanych hernich jednotek
	 * @param	array			$customer		// Pole s informacemi o zakaznikovi
	 * 
	 * @return	bool|string		$result			// Uspech == TRUE / Chyba == FALSE
	 */
	public function createReservationRequest(Carbon $date, array $units, array $customer)
	{
		// 1.) Kontrola - DATE
		if(Carbon::now("Europe/Prague")->startOfDay() > $date) // Starsi nez dnesni datum
			return false;

		// 2.) Kontrola - BANLIST (Email + Telefon)
		$resBlEmail = $this->database->query('SELECT * FROM reservation_banlist WHERE type = ? AND value = ? LIMIT 1', 'EMAIL', $customer['email']);
		if($resBlEmail && $resBlEmail->getRowCount() > 0) // Email BAN
			return false;

		$resBlPhone = $this->database->query('SELECT * FROM reservation_banlist WHERE type = ? AND value = ? LIMIT 1', 'PHONE', $customer['phone']);
		if($resBlPhone && $resBlPhone->getRowCount() > 0) // Telefon BAN
			return false;

		// 3.) Kontrola - UNITS
		if(count($units /*, COUNT_RECURSIVE*/) > $this->getUnitsCountTotal()) // Prilis mnoho Units
			return false;

		foreach($units as $item)
		{
			$_tableId = $this->getTableIdByUnitName($item);

			if(empty($_tableId) || $_tableId == NULL) // Chybi _tableId (neplatny Unit Name?)
				return false;

			$unitHour = (int)substr($item, 0, 2);
			$startDate = Carbon::create($date->year, $date->month, $date->day, $unitHour, 0, 0);

			if($this->checkReservationSlot($_tableId, $startDate, 60) == false) // Slot jiz byl obsazen
				return false;
		}

		// 4.) Zapsat data 'customer' do DB -> ziskam customerID
		$resultC = $this->database->table('customer')->insert($customer);
		if(!$resultC) // Nepodarilo se zapsat do DB
			return false;
		$customerID = $resultC->id;

		// 5.) Vygenerovani Auth Code
		$authCode = $this->getRandomAuthCode(4);
		if($authCode == str_repeat('9', 4)) // Nepodarilo se najit unikatni AuthCode
			return false;

		// 6.) Zapsat data 'reservation_request' do DB
		$resultR = $this->database->table('reservation_request')->insert([
			'customerID'	=> $customerID,
		//	'created'		=> Carbon::now(),
			'date'			=> (string)sprintf("%4d-%02d-%02d", (int)$date->year, (int)$date->month, (int)$date->day),
		//	'firstHour'		=> $this->getReservationFirstHour(json_encode($units)),
			'firstHour'		=> NULL, // MOVED TO THE COMPLETE RES. REQ. PROCESS
			'units'			=> json_encode($units),
			'authCode'		=> $authCode,
			'status'		=> 'NEW',
			'reminder'		=> 'DISABLED',
		]);
		if(!$resultR) // Nepodarilo se zapsat do DB
			return false;

		// 7.) Odeslat SMS (tam bude authCode)
		// ZAKOMENTUJ, PRO TESTOVANI (ID vytahnes z DB) !!!
		$this->smsbrana->sendSMS($customer['phone'], "Vas SMS Kod pro potvrzeni rezervace VRko.cz je ". $authCode .". Tesime se na Vas! :)");

		// Hotovo ???
		//return (string)$authCode; // AUTH-OVERRIDE (TEMP)
		return true; // "OK: Reservation Request Created...";
	}

	/** Complete Reservation from Reservation request in Database
	 * @param	string			$authCode		// Autorizacni kod
	 * @param	string			$email			// Emailova adesa (!!! TODO !!!)
	 * 
	 * @return	bool			$result			// Uspech == TRUE / Chyba == FALSE
	 */
	public function completeReservationRequest(string $authCode, string $email = '')
	{
		// 1.) Ziskat 'reservation_request' z DB (podle $authCode a ...?)
		$resultR = $this->database->query('SELECT * FROM reservation_request WHERE authCode = ? AND status = ? LIMIT 1', $authCode, 'NEW');
		if(!$resultR || $resultR->getRowCount() != 1) // Nepodarilo ziskat data z DB
			return "Chyba: Zadaný kód je neplatný, nebo vypršela jeho platnost."; // EB01;

		$reservationRequest = $resultR->fetch();

		$date = Carbon::create($reservationRequest['date']->format('Y-m-d H:i:s.u'), "Europe/Prague")->startOfDay();
		$units = json_decode($reservationRequest['units']);

		// 2.) Ziskat 'customer' z DB (podle 'reservation_request'.'customerID')
		$resultC = $this->database->query('SELECT * FROM customer WHERE id = ?', $reservationRequest['customerID']);
		if(!$resultC || $resultC->getRowCount() != 1) // Nepodarilo ziskat data z DB
			return "Chyba: Zadaný kód je neplatný, nebo vypršela jeho platnost."; // EB02-A;

		$customer = $resultC->fetch();

		// !!! HARDCODED: Kontrola emailove adresy zakaznika
		if($customer['email'] !== $email)
			return "Chyba: Zadaný kód je neplatný, nebo vypršela jeho platnost."; // EB02-B;

		// 3.) Vytvorit zakaznika v Dotykacce => Ziskam 'dotykackaID'
		// (POUZE kdyz 'customer'.'dotykackaID' == NULL; jinak ziskat z DB)
		$dotykackaID = (string)$customer['dotykackaID'];
		if(empty($dotykackaID))
		{
			$newCustomer = $this->doty2->createCustomers([
				$this->doty2->CustomerSchema($customer['name'], $customer['surname'], $customer['email'], $customer['phone'])
			]);

			if(empty($newCustomer[0]->id)) // Nepodarilo se vytvorit zakaznika v Dotykacce (nedostali jsme ID)
				return "Chyba: Nepodařilo se vytvořit záznam zákazníka."; // EB03

			$dotykackaID = $newCustomer[0]->id;
		}

		// 4.) Vytvorit zaznam v EcoMailu => Ziskam 'ecomailID'
		// (POUZE kdyz 'customer'.'subscribe' == 1 && 'customer'.'ecomailID' == NULL)
		$ecomailID = $customer['ecomailID'];
		/*if($customer['subscribe'] == 1 && empty($ecomailID))
		{
			$ecomailData = $this->ecomail->addSubscriber(2, [	// <<<<<<<<<<< BRZDA???
				'name'		=> $customer['name'],
				'surname'	=> $customer['surname'],
				'email'		=> $customer['email'],
				'phone'		=> $customer['phone'],
				'vokativ'	=> $customer['name'],
				'vokativ_s'	=> $customer['surname'],
				], FALSE, TRUE, TRUE);
			$ecomailID = (!isset($ecomailData['id'])) ? NULL : $ecomailData['id']; // V pripade chyby je NULL
		}*/

		// 5.) Aktualizovat 'customer' v DB ('dotykackaID', 'ecomailID')
		$this->database->query('UPDATE customer SET', [
			'dotykackaID'	=> $dotykackaID,
			'ecomailID'		=> $ecomailID,
		], 'WHERE id = ?', $reservationRequest['customerID']);

		// 6.) Pripravit data pro Rezervace (a zkontrolovat jestli je SLOT volny!)
		$startHour = 24;
		$note = $customer['name']." ".$customer['surname']." / ".$customer['phone'];
		$reservations = [];
		foreach($units as $item)
		{
			$_tableId = $this->getTableIdByUnitName($item);

			if(empty($_tableId) || $_tableId == NULL) // Chybi _tableId (neplatny Unit Name?)
				return "Chyba: Vámi vybrané místo se nám nepodařilo najít. Prosím, zkuste zadat novou rezervaci."; // EB06-A

			$unitHour = (int)substr($item, 0, 2);

			if($unitHour < $startHour)
				$startHour = $unitHour;

			$reservation = $this->prepareReservationSlot($dotykackaID, $_tableId, $date->year, $date->month, $date->day, $unitHour, $note);
			if($reservation == false) // Slot jiz byl obsazen
				return "Chyba: Některé z vybraných míst je již obsazeno. Prosím, vyberte jiný čas rezervace."; // EB06-B

			$reservations[] = $reservation;
		}

		// 7.) Vytvorit Rezervace v Dotykacce
		if(empty($reservations))
			return "Chyba: Data o rezervaci nejsou k dispozici. Prosím, kontaktujte nás."; // EB07-A

		$resultDtk = $this->doty2->createReservations($reservations);
		if(empty($resultDtk[0]->id))
			return "Chyba: Rezervaci se nepodařilo založit. Prosím, kontaktujte nás."; // EB07-B

		// 8.) Aktualizovat 'reservation_request' v DB (status = 'CONFIRMED', [vymazat 'authCode' ??? ])
		$this->database->query('UPDATE reservation_request SET', [
			'firstHour'		=> $startHour,
			'status'		=> 'CONFIRMED',
			'reminder'		=> 'WAITING',
			'authCode'		=> NULL,
		], 'WHERE id = ?', $reservationRequest['id']);

		// 9.) TODO: Odeslat Email (Potvrzeni o Rezervaci)
		$mailTemplateData = [
		//	'customer'		=> $customer,
			'date'			=> $date->format('d.m.Y'),
		//	'hour'			=> getReservationFirstHour($reservationRequest['units']),
			'hour'			=> $startHour,
			'units'			=> var_export($units, true),
		//	'resultDtk'		=> var_export($resultDtk, true),
		];
		$this->sendMail($customer['email'], "@confirmationEmail", "Potvrzení o rezervaci VRko.cz", $mailTemplateData);

		// 10.) Synchronizace rezervaci pro cely den (podle 'reservation_request'.'date')
		$this->syncReservationsByDay($date->year, $date->month, $date->day); // SYNC
		$this->renderOccupancy($date->year, $date->month, $date->day); // REDRAW

		// 11.) Vytvoreni pozadavku na "hodnoceni"
		$reviewRequestResult = $this->reviews->createRequest(
			$date,
			$reservationRequest['units'],
			$customer['name']." ".$customer['surname'],
			$customer['email'],
			$customer['phone'],
			$reservationRequest['id']
		);
		/*if($reviewRequestResult === false)
			return "Chyba: Nepodařilo se vytvořit požadavek na hodnocení zážitku."; // EB11*/

		// Hotovo ???
		return true; // "OK: Reservation Request Completed...";
	}

	// ### SEND MAIL ###
	function sendMail($recipient, $templateName, $subject, $data)
	{
		// TODO: EMAIL Validator: $recipient

		$mailMsg = new Mail\Message();
		$mailSrv = new Mail\SmtpMailer([	// WEDOS
			'host'		=> '',		// SMTP server
			'port'		=>  587,	// Port: 465(ssl) / 587(tls)
			'username'	=> '',		// Lgn
			'password'	=> '',		// Pwd
			'secure'	=> 'tls'	// SSL / TLS
		]);

		$mailMsg->setFrom("Rezervace VRko.cz <info@vrko.cz>");

		$mailMsg->addTo($recipient);
		$mailMsg->setSubject($subject);

		$latte = new Latte\Engine;
		$template = $latte->renderToString(__DIR__ . "/" . $templateName . ".latte", $data);
		$mailMsg->setHtmlBody($template, __DIR__ . "/../../../www/img/email/");
		$mailSrv->send($mailMsg);
	}
}
