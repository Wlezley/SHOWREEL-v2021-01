<?php

//declare(strict_types=1);

namespace App\Model\Voucher;

use Latte;
use Nette;
use App\Model;
use App\Model\Dotykacka;

use Nette\Utils\Json;
use Nette\Utils\Random;
use Nette\Utils\ArrayHash;
use Nette\Utils\Validators;
use Nette\Database\Context;
use Tracy\Debugger;

// DATE / TIME
use Carbon\Carbon;

// CREATE PDF
use Mpdf\Mpdf as mPDF;
use Nette\Application\UI\ITemplateFactory;

// SEND MAIL
use Nette\Mail;


class Voucher
{
	/** @var Nette\Database\Context */
	protected $database;

	/** @var Dotykacka\DotykackaApi2 */
	private $doty2;


	public function __construct(Context $database,
								Dotykacka\DotykackaApi2 $doty2)
	{
		$this->database = $database;
		$this->doty2 = $doty2;
	}

	// ######################################################

	/** Vygeneruje nahodny ciselny kod
	 * @param	integer			$size
	 * @param	string|NULL		$table
	 * @param	string|NULL		$field
	 *
	 * @return	int|NULL
	 */
	public function getRandomCode($size, $table = NULL, $field = NULL)
	{
		if($table == NULL || $field == NULL)
		{
			return Random::generate($size, '1-9');
		}

		$randomCode = NULL;
		$counter = 0;
		$limit = 10;

		for($counter; $counter < $limit; $counter++)
		{
			$randomCode = Random::generate($size, '1-9');
			$result = $this->database->query('SELECT * FROM `'.$table.'` WHERE ? = ? LIMIT 1', $field, $randomCode);
			if(!isset($result) || $result->getRowCount() == 0)
			{
				break;
			}
		}

		return ($counter == $limit) ? str_repeat('9', $size) : $randomCode;
	}

	// ######################################################

	/** Vygeneruje nahodny ciselny kod EAN-13 (vcetne CHECKSUM)
	 * @param	string			$prefix
	 * @param	string|NULL		$table
	 * @param	string|NULL		$field
	 *
	 * @return	string|NULL
	 */
	public function getRandomEAN13($prefix = '', $table = NULL, $field = NULL)
	{
		// TODO: Check if $prefix is STRING of DIGITS (empty string allowed)

		$size = 12 - strlen($prefix);
		$charlist = empty($prefix) ? '1-9' : '0-9';

		if($size <= 0)
			return NULL;

		if($table == NULL || $field == NULL)
			return $this->getChecksum_EAN13($prefix . (string)Random::generate($size, $charlist));

		$randomCode = NULL;
		$counter = 0;
		$limit = 10;

		for($counter; $counter < $limit; $counter++)
		{
			$randomCode = $this->getChecksum_EAN13($prefix . (string)Random::generate($size, $charlist));
			$result = $this->database->query('SELECT * FROM `'.$table.'` WHERE ? = ? LIMIT 1', $field, $randomCode);
			if(!isset($result) || $result->getRowCount() == 0)
			{
				break;
			}
		}

		return ($counter == $limit) ? $this->getChecksum_EAN13($prefix . str_repeat('9', $size)) : $randomCode;
	}

	// ######################################################

	/** GET BARCODE EAN-8 + CHECKSUM (LAST NUMBER)
	 * @param	string		$digits		// 7 DIGITS as string
	 *
	 * @return	string					// 8 DIGITS as string (with CHECKSUM)
	 */
	function getChecksum_EAN8($barCode)
	{
		$sum = 0;
		$arr = str_split($barCode, 1);

		for($i = 0; $i < count($arr); $i++)
		{
			if($i === 0 || $i % 2 === 0)
			{
				$arr[$i] = $arr[$i] * 3;
			}
			$sum += $arr[$i];
		}
		$check_digit = (10 - ($sum % 10)) % 10;
		return $barCode . $check_digit;
	}

	// ######################################################

	/** GET BARCODE EAN-13 + CHECKSUM (LAST NUMBER)
	 * @param	string		$digits		// 12 DIGITS as string
	 *
	 * @return	string					// 13 DIGITS as string (with CHECKSUM)
	 */
	function getChecksum_EAN13($digits)
	{
		// First change digits to a string so that we can access individual numbers
		$digits = (string)$digits;
		// 1. Add the values of the digits in the even-numbered positions: 2, 4, 6, etc.
		$even_sum = $digits{1} + $digits{3} + $digits{5} + $digits{7} + $digits{9} + $digits{11};
		// 2. Multiply this result by 3.
		$even_sum_three = $even_sum * 3;
		// 3. Add the values of the digits in the odd-numbered positions: 1, 3, 5, etc.
		$odd_sum = $digits{0} + $digits{2} + $digits{4} + $digits{6} + $digits{8} + $digits{10};
		// 4. Sum the results of steps 2 and 3.
		$total_sum = $even_sum_three + $odd_sum;
		// 5. The check character is the smallest number which, when added to the result in step 4, produces a multiple of 10.
		$next_ten = (ceil($total_sum/10))*10;
		$check_digit = $next_ten - $total_sum;
		return $digits . $check_digit;
	}

	// #############################################################################################################################

	/** Vytvoření objednávky včetně order_items
	 * @param	double		$price_total	// Cena celkem
	 * @param	integer		$count			// Pocet poukazu
	 * @param	string		$z_name			// Zakaznik / Jmeno
	 * @param	string		$z_surname		// Zakaznik / Prijmeni
	 * @param	string		$z_email		// Zakaznik / Email
	 * @param	string		$z_phone		// Zakaznik / Telefon
	 *
	 * @return	integer|NULL				// ID Objednavky
	 */
	function createOrder($price_total, $count, $z_name, $z_surname, $z_email, $z_phone)
	{
		if($price_total <= 0 || $count <= 0 || $count > 10)
			return NULL;

		$orderData = [
		  //'id'			=> $orderId,		// ID Objednavky
		  //'invoice_id'	=> NULL,			// Unknown
		  //'date_created'	=> Carbon::now()->format('Y-m-d H:i:s'),	// CURRENT_TIMESTAMP
			'price_total'	=> $price_total,	// Cena celkem
			'z_name'		=> $z_name,			// Zakaznik / Jmeno
			'z_surname'		=> $z_surname,		// Zakaznik / Prijmeni
			'z_email'		=> $z_email,		// Zakaznik / Email
			'z_phone'		=> $z_phone,		// Zakaznik / Telefon
		];
		$result = $this->database->table('orders')->insert($orderData);
		$orderId = $result->id;

		if(!isset($orderId) || $orderId <= 0)
			return NULL;

		$orderItems = [];
		for($i = 0; $i < $count; $i++)
		{
			$orderItems[] = [
			  //'id'			=> NULL,					// ID Polozky
				'order_id'		=> $orderId,				// ID Objednavky
				'item_name'		=> "Herní poukaz VRko.cz",	// Nazev polozky
				'item_count'	=> 1,						// Pocet kusu
				'item_price'	=> ($price_total / $count),	// Cena za kus
			];
		}
		$result = $this->database->table('order_items')->insert($orderItems);

		return $orderId;
	}

	/** Dokončení objednávky (a vygenerování voucherů)
	 * @param	integer		$orderId		// ID Objednávky
	 *
	 * @return	bool|NULL
	 */
	function completeOrder($orderId)
	{
		// INIT DATA
		$todayDate = Carbon::now()->format('Y-m-d');
		$todayDatetime = Carbon::now()->format('Y-m-d H:i:s');

		// GET ORDER
		$result = $this->database->query('SELECT * FROM orders WHERE id = ? LIMIT 1', $orderId);
		if(!isset($result) || $result->getRowCount() != 1)
			return false;

		$orderData = $result->fetch();
		$price_total = $orderData['price_total'];

		// GET ORDER_ITEMS
		$result = $this->database->query('SELECT * FROM order_items WHERE order_id = ?', $orderId);
		if(!isset($result) || $result->getRowCount() == 0)
			return false;

		$orderItems_count = $result->getRowCount();
		$orderItems = $result->fetchAll();
		$item_price = ($price_total / $orderItems_count);

		// GENERATE VOUCHER DATA
		foreach($orderItems as /*$key =>*/ $item)
		{
			$result = $this->database->query('SELECT * FROM voucher WHERE order_id = ? AND order_item_id = ?', $orderId, $item['id']);
			if(isset($result) && $result->getRowCount() != 0)
				continue;

			$voucherID = $this->getRandomCode(6, 'voucher', 'voucher_id');
		  //$voucherEAN = '6660' . $this->getRandomCode(9, 'voucher', 'voucher_ean');
			$voucherEAN = $this->getRandomEAN13('6660', 'voucher', 'voucher_ean');
			$voucherData = [
			  //'id'			=> NULL,			// UID (možná UID pro voucher EAN6?)
				'order_id'		=> $orderId,		// ID Objednavky
				'order_item_id'	=> $item['id'],		// ID Polozky Objednavky
				'voucher_id'	=> $voucherID,		// EAN-6
				'voucher_ean'	=> $voucherEAN,		// EAN-13
				'voucher_date'	=> $todayDate,		// DATUM
			];
			$result = $this->database->table('voucher')->insert($voucherData);
		}

		// GET VOUCHER DATA
		$result = $this->database->query('SELECT * FROM voucher WHERE order_id = ?', $orderId);
		if(!isset($result) || $result->getRowCount() == 0 || $result->getRowCount() != $orderItems_count)
			return false;

		$voucherDataAll = $result->fetchAll();

		// CREATE INVOICE (id & descriptor)
		$invoiceId = NULL;
		$result = $this->database->query('SELECT * FROM invoice WHERE order_id = ? LIMIT 1', $orderId);
		if(isset($result) && $result->getRowCount() == 1)
		{
			$invoiceId = $result->fetch()->id;
		}
		else
		{
			$invoiceData = [
			  //'id'			=> NULL,			// ID Faktury / Uctenky
				'order_id'		=> $orderId,		// ID Objednavky
			  //'date_created'	=> $todayDatetime,	// Datum vytvoreni 
				'date_payed'	=> $todayDatetime,	// Datum a cas uhrady
			];
			$result = $this->database->table('invoice')->insert($invoiceData);
			$invoiceId = $result->id;
		}
		if(!isset($invoiceId) || $invoiceId <= 0)
			return false;

		// PDF: RENDER SEED
		$seedPDF = $this->getSeedPDF($invoiceId, $orderItems_count, $item_price, $price_total, $todayDatetime);

		// VOUCHER POST-PROCESS
		$files = [];
		$files['uctenka-'. $invoiceId . '.pdf'] = $seedPDF;
		foreach($voucherDataAll as $voucher)
		{
			// DOTYKACKA - CREATE SALE & STOCKUP ITEM (DISABLE FOR TESTING PURPOSES ???)
			$this->createSaleItem($invoiceId, $voucher['voucher_id'], $voucher['voucher_ean'], $item_price);

			// PDF: RENDER VOUCHER FILES
			$voucherPDF = $this->getVoucherPDF($voucher['voucher_id'], $voucher['voucher_ean'], $voucher['voucher_date']);
			$files['voucher-' . $voucher['voucher_ean'] . '.pdf'] = $voucherPDF;
		}

		// VOUCHER COUNT SPELLING
		$spelling = "herních poukazů"; // Default
		switch((int)$orderItems_count)
		{
			case 1: $spelling = "herní poukaz"; break;
			case 2: case 3: case 4: $spelling = "herní poukazy"; break;
		}

		// SEND MAIL
		$mailTemplateData = [
			'orderId'		=> $orderId,
			'pocetPoukazu'	=> (int)$orderItems_count,
			'spelling'		=> $spelling,
		];
		$this->sendMail($orderData['z_email'], "@email-success", "Herní poukaz VRko.cz", $files, $mailTemplateData);

		// SUCCESS
		return true;
	}

	// #############################################################################################################################

	/** Vytvoření prodejní položky DOTYKAČKA
	 * @param	string		$invoiceId		// ID Faktury (uctenky)
	 * @param	string		$voucherId		// ID Voucheru (EAN-6)
	 * @param	string		$voucherEan		// EAN-13 Voucheru
	 * @param	string		$price			// Cena
	 *
	 * @return	integer|NULL				// ID Objednavky
	 */
	function createSaleItem($invoiceId, $voucherId, $voucherEan, $price)
	{
		$_supplierId = '8436897805239446';
		$name = "Herní poukaz - " . $voucherEan;		// Nazev
		$description = "55 minut / 1 ks VR jednotky";	// Popis
		$categoryId = 8436894865124667;					// Kategorie: VOUCHERY
		$rawPrice = ($price * (-1));					// Bez DPH
		$vatPrice = ($price * (-1));					// Vc. DPH

		// DOTYKACKA - Vytvorit prodejni polozky
		$saleItem = [
			'_categoryId'			=> $categoryId,		// Long		ID Kategorie, do které položka spadá
			'_cloudId'				=> '323467526',		// Integer	ID Cloudu (není potřeba - natáhne se z API requestu)
			'deleted'				=> false,			// Bool		- Smazáno?
			'description'			=> $description,	// String	Popis položky
			'discountPercent'		=> '0.0',			// Double	Sleva v procentech
			'discountPermitted'		=> true,			// Bool		- Povolit možnost slevy?
			'display'				=> true,			// Bool		- Zobrazit?
			'ean'					=> [$voucherEan,
										$voucherId],	// String[]	Pole s EANy pro položku
			'flags'					=> '0',				// Integer	Příznaky (nastavení)
			'hexColor'				=> '#F32C24',		// String	Barva položky v HEXu
			'name'					=> $name,			// String	Název položky
			'onSale'				=> false,			// Bool		- Je položka "na prodej"?
			'packageItem'			=> '1.0',			// Double	Počet balíčků na položku
			'packaging'				=> '1.0',			// Double	Počet položek v balíčku
			'packagingMeasurement'	=> '1.0',			// Double	Měrná jednotka balení (?)
			'points'				=> '0.0',			// Double	(?) Body za nákup produktu  (?)
			'priceWithVat'			=> $vatPrice,		// Double	Cena vč. DPH
			'priceWithoutVat'		=> $rawPrice,		// Double	Cena bez DPH
			'requiresPriceEntry'	=> false,			// Bool		- Vyžaduje zadání ceny?
			'stockDeduct'			=> true,			// Bool		- Odečítat ze skladu při prodeji?
			'stockOverdraft'		=> 'DISABLE',		// Enum		Přečerpání zásob (určuje, zda je možné jít s počtem položek na skladě do mínusu) (ALLOW, DISABLE)
			'subtitle'				=> $description,	// String	Krátká poznámka
		//	'supplierProductCode'	=> NULL,			// 			(?) Kód produktu dodavatele (?)
			'unit'					=> 'Piece',			// Enum		Jednotka (kus)
			'unitMeasurement'		=> 'Piece',			// Enum		Měrná jednotka (kus)
			'vat'					=> '1.0',			// Double	Poměr násobku DPH
			'versionDate'			=> NULL,			// TimeStamp
		];
		$saleItems = [$saleItem];
		$cpResponse = $this->doty2->createProduct($saleItems);

		foreach($cpResponse as $item)
		{
			$itemId = $item->id;

			$skladItems [] = [
				'_productId'	=> $itemId,		// Long		?
				'externalId'	=> NULL,		// String	?
				'purchasePrice'	=> 0.00,		// Double	? ($rawPrice)
				'quantity'		=> '1.0',		// Double	-- negative for corrections
				'sellPrice'		=> $rawPrice,	// Double	?
			];

			$this->doty2->stockupToWarehouse($_supplierId, $invoiceId, $name, false, $skladItems);
		}
	}

	// ### PDF - SEED DATA ###
	function getSeedPDF($invoiceId, $orderItems_count, $item_price, $price_total, $date)
	{
		$dataHTML = [
			'seedId'		=> $invoiceId,
			'voucherPocet'	=> $orderItems_count,
			'priceItem'		=> $item_price,
			'priceTotal'	=> $price_total,
			'dateTodayFull'	=> $date,
			'logoImg'		=> __DIR__ . "/../../../www/img/logo/vrko-ruzova-horizontal.png"
		];

		$latte = new Latte\Engine;
		$template = $latte->renderToString(__DIR__ . "/@seed.latte", $dataHTML);
		$stylesheet = $latte->renderToString(__DIR__ . "/@seed.css");

		$pdf = new mPDF([
			'mode'					=> 'utf-8',		// Charset
			'format'				=> 'A4',		// Page format
			'tempDir'				=> '../temp',	// Temp directory
			'ignore_invalid_utf8'	=> true,		// 
			//'useOnlyCoreFonts'	=> true,		// 
		]);

		$pdf->SetTitle("Účtenka VRko.cz");
		$pdf->SetAuthor("https://vrko.cz/");
		$pdf->SetDisplayMode("fullpage");

		//$pdf->WriteHTML($template);		// \Mpdf\HTMLParserMode::DEFAULT_MODE
		$pdf->WriteHTML($stylesheet, 1);	// \Mpdf\HTMLParserMode::HEADER_CSS
		$pdf->WriteHTML($template, 2);		// \Mpdf\HTMLParserMode::HTML_BODY

		return $pdf->Output("uctenka-" . $invoiceId . ".pdf", "S");
	}

	// ### PDF - VOUCHER DATA ###
	function getVoucherPDF($voucherId, $voucherEAN, $voucherDate)
	{
		$dataHTML = [
			'voucherId'		=> $voucherId,
			'voucherEAN'	=> $voucherEAN,
			'voucherDate'	=> $voucherDate
		];

		$dataCSS = [
			'backgroundImg'	=> __DIR__ . "/../../../www/img/poukazy/poukaz_blank.png"
		];

		$latte = new Latte\Engine;
		$template = $latte->renderToString(__DIR__ . "/@voucher.latte", $dataHTML);
		$stylesheet = $latte->renderToString(__DIR__ . "/@voucher.css", $dataCSS);

		$pdf = new mPDF([
			'mode'					=> 'utf-8',			// Charset
			//'format'				=> [175.0, 86.0],	// Page format
			'format'				=> [230.0, 112.5],	// Page format
			'tempDir'				=> '../temp',		// Temp directory
			'ignore_invalid_utf8'	=> true,
			'margin_left'			=> 0,
			'margin_right'			=> 0,
			'margin_top'			=> 0,
			'margin_bottom'			=> 0,
		]);

		$pdf->SetTitle("Herní poukaz VRko.cz");
		$pdf->SetAuthor("https://vrko.cz/");
		$pdf->SetDisplayMode("fullpage");

		//$pdf->WriteHTML($template);		// \Mpdf\HTMLParserMode::DEFAULT_MODE
		$pdf->WriteHTML($stylesheet, 1);	// \Mpdf\HTMLParserMode::HEADER_CSS
		$pdf->WriteHTML($template, 2);		// \Mpdf\HTMLParserMode::HTML_BODY

		/*$pdf->SetProtection([
			//'copy',			// 
			'print',			// 
			//'modify',			// 
			//'annot-forms',	// 
			//'fill-forms',		// 
			//'extract',		// 
			//'assemble',		// 
			'print-highres',	// 
		]);*/

		return $pdf->Output("voucher-" . $voucherEAN . ".pdf", "S");
	}

	// ### SEND MAIL ###
	function sendMail($recipient, $templateName, $subject, $files, $data)
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

		$mailMsg->setFrom("Vouchery VRko.cz <info@vrko.cz>");
		$mailMsg->addTo($recipient);
		$mailMsg->addBcc("faktura@vrko.cz");
		$mailMsg->setSubject($subject); // Vaše objednávka herního poukazu VRko.cz

		foreach($files as $fileName => $fileData)
		{
			$mailMsg->addAttachment($fileName, $fileData, "application/pdf");
		}

		$latte = new Latte\Engine;
		$template = $latte->renderToString(__DIR__ . "/" . $templateName . ".latte", $data);
		$mailMsg->setHtmlBody($template, __DIR__ . "/../../../www/img/email/");
		$mailSrv->send($mailMsg);
	}
}
