<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use App\Model;
use App\Model\Reservation;

use Nette\Utils\ArrayHash;
use Nette\Utils\Validators;
use Nette\Database\Context;
use Tracy\Debugger;

use Carbon\Carbon;


class PartialsPresenter extends BasePresenter
{
	/** @var Nette\Database\Context */
	protected $database;

	/** @var Reservation\Calendar */
	private $calendar;

	// Referer check settings
	const REFERER_CHECK = 'www-dev.vrko.cz'; // DEBUG
	//const REFERER_CHECK = 'vrko.cz'; // PRODUCTION

	public function __construct(Context $database,
								Reservation\Calendar $calendar)
	{
		Debugger::$showBar = false; // Disable Tracy Debug Bar
		$this->database = $database;
		$this->calendar = $calendar;
	}

	public function renderDefault()
	{
		/* TEST REZERVACE
		$this->template->date = "30.04.2021";
		$this->template->hour = 16;

		// TEST VOUCHER
		$this->template->orderId = 123456789;
		$this->template->pocetPoukazu = 3;

		// VOUCHER COUNT SPELLING
		$spelling = "herních poukazů"; // Default
		switch((int)$this->template->pocetPoukazu)
		{
			case 1: $spelling = "herní poukaz"; break;
			case 2: case 3: case 4: $spelling = "herní poukazy"; break;
		}
		$this->template->spelling = $spelling;
		*/
	}

	public function actionDefault($hash)
	{
		//return false;
	}

	public function renderSelectday($year, $month) // TODO: Just parse DATE from one $string
	{
		$this->template->error = true; // Error Handler (Default: true)

		// Check input DATE before sending to major functions (Memory overflow protection)
		if (!Validators::is($year, 'numericint:' . (Carbon::now()->year + 0) . '..' . (Carbon::now()->year + 1)) ||
			!Validators::is($month, 'numericint:1..12') ||
			$year  < Carbon::now()->year  || $year  > Carbon::now()->addMonth()->year ||
			$month < Carbon::now()->month || $month > Carbon::now()->addMonth()->month)
		{
			header("HTTP/1.0 404 Not Found");
			return false;
		}

		// Referer check
		if (empty($_SERVER['HTTP_REFERER']) || empty(parse_url($_SERVER['HTTP_REFERER'])) || parse_url($_SERVER['HTTP_REFERER'])['host'] !== self::REFERER_CHECK)
		{
			header("HTTP/1.0 404 Not Found");
			return false;
		}

		// Render data
		$renderData = $this->calendar->getRenderData_Selectday($year, $month);
		if(!$renderData)
		{
			header("HTTP/1.0 404 Not Found");
			return false;
		}

		// No error anymore...
		$this->template->error = false;

		$this->template->monthName	= $renderData['monthName'];
		$this->template->dayNames	= $renderData['dayNamesShort'];
		$this->template->pagination	= $renderData['pagination'];
		$this->template->now = $this->calendar->getDateNowArray();
		$this->template->calMonthPage = $renderData['calMonthPage'];

		// LEGEND
		$this->template->palette = $renderData['palette'];

		// NAVIGATOR
		$this->template->prevMonthHtml = '<div>&nbsp;</div>';
		$this->template->nextMonthHtml = '<div>&nbsp;</div>';
		if($year == Carbon::now()->addMonth()->year && $month == Carbon::now()->addMonth()->month) {
			$this->template->prevMonthHtml = '<a id="redir-month-prev" title="Předchozí měsíc"><div><i class="fas fa-angle-double-left"></i></div></a>';
		}
		if($year == Carbon::now()->year && $month == Carbon::now()->month) {
			$this->template->nextMonthHtml = '<a id="redir-month-next" title="Následující měsíc"><div><i class="fas fa-angle-double-right"></i></div></a>';
		}

		// DEBUG
		$this->template->debug = "N/A";
	}

	public function renderSelecthour($year, $month, $day) // TODO: Just parse DATE from one $string
	{
		$this->template->error = true; // Error Handler (Default: true)

		// Check input DATE before sending to major functions (Memory overflow protection)
		if (!Validators::is($year, 'numericint:' . (Carbon::now()->year + 0) . '..' . (Carbon::now()->year + 1)) ||
			!Validators::is($month, 'numericint:1..12') ||
			!Validators::is($day, 'numericint:1..' . Carbon::create($year, $month, 1)->endOfMonth()->day))
		{
			header("HTTP/1.0 404 Not Found");
			return false;
		}

		// Check input date range
		$selDate = Carbon::create($year, $month, $day);						// SELECTED
		$nowDate = Carbon::now()->startOfDay();								// NOW DATE
		$endDate = Carbon::now()->addMonth()->endOfMonth()->startOfDay();	// END DATE
		if($selDate < $nowDate || $selDate > $endDate)
		{
			header("HTTP/1.0 404 Not Found");
			return false;
		}

		// Referer check
		if (empty($_SERVER['HTTP_REFERER']) || empty(parse_url($_SERVER['HTTP_REFERER'])) || parse_url($_SERVER['HTTP_REFERER'])['host'] !== self::REFERER_CHECK)
		{
			header("HTTP/1.0 404 Not Found");
			return false;
		}

		// Render data
		$renderData = $this->calendar->getRenderData_Selecthour($year, $month, $day);
		if(!$renderData)
		{
			header("HTTP/1.0 404 Not Found");
			return false;
		}

		// No error anymore...
		$this->template->error = false;

		$this->template->year		= (int)$year;
		$this->template->month		= (int)$month;
		$this->template->day		= (int)$day;

		$this->template->monthName	= $renderData['monthName'];
		//$this->template->dayName	= $renderData['dayName'];

		$this->template->occupancyData = $renderData['occupancyData'];

		// DEBUG
		$this->template->debug = "N/A";
	}
}
