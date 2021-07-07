<?php

declare(strict_types=1);

namespace App\Model\Dotykacka;

use Nette;
use App\Model;
use Nette\Utils\Json;
use Nette\Utils\ArrayHash;
use Tracy\Debugger;

use Carbon\Carbon;


class DotykackaApi2
{
	/** @var String */
	protected $dotykackaUrl;

	/** @var String */
	protected $refreshToken;

	/** @var Integer */
	protected $cloudId;

	/** @var Integer */
	protected $branchId;

	/** @var Integer */
	protected $warehouseId;

	/** @var Array */
	protected $tables;

	/** @var String */
	private $accessToken;


	public function __construct($dotykackaUrl, $refreshToken, $cloudId = NULL, $branchId = NULL, $warehouseId = NULL, $tables = array())
	{
		$this->dotykackaUrl	= $dotykackaUrl;
		$this->refreshToken	= $refreshToken;
		$this->cloudId		= $cloudId;
		$this->branchId		= $branchId;
		$this->warehouseId	= $warehouseId;
		$this->tables		= $tables;
		$this->accessToken	= $this->getAccessToken();
	}


	// #####################
	// ## HTTP HANDLERS ##
	// #################

	/** Send HTTP Request
	 * @param	string		$path			// Path part of URL
	 * @param	string		$type			// Type of request [GET, POST, PUT, PATCH, DELETE, OPTIONS, ...]
	 * @param	string|NULL	$authorization	// Authorization string, format: "<type> <credentials>" (RFC7235, RFC6750)
	 * @param	array|NULL	$data			// POST / PUT / PATCH Data array
	 * 
	 * @return	array|NULL	$result
	 */
	private function sendRequest($path, $type, $authorization = NULL, $data = NULL)
	{
		$httpHeader = array();
		$httpHeader [] = "Authorization: " . $authorization;
		$httpHeader [] = "Accept: application/json";
		$httpHeader [] = "Content-Type: application/json";

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->dotykackaUrl . $path);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		//curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeader);

		if(!empty($data))
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

		switch($type)
		{
			case 'GET':		// GET
				break;
			case 'POST':	// POST + DATA
				curl_setopt($ch, CURLOPT_POST, TRUE);
				break;
			case 'PUT':		// CUSTOM + DATA
			case 'PATCH':	// CUSTOM + DATA
			case 'DELETE':	// CUSTOM
			case 'OPTIONS':	// CUSTOM
			default:		// UNK
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
				break;
		}

		$result = curl_exec($ch);
		curl_close($ch);

		if(empty($result))
			return NULL;

		return json_decode($result); // , true);
	}

	/** Send HTTP GET Request */
	private function sendHttpGet($path, $accessToken)
	{
		return $this->sendRequest($path, 'GET', 'Bearer ' . $accessToken, NULL);
	}

	/** Send HTTP POST Request + DATA */
	private function sendHttpPost($path, $accessToken, $data)
	{
		return $this->sendRequest($path, 'POST', 'Bearer ' . $accessToken, $data);
	}

	/** Send HTTP PUT Request + DATA */
	private function sendHttpPut($path, $accessToken, $data) // TODO: Header "If-Match" is REQUIRED (ETag to update only if not changed.)
	{
		return $this->sendRequest($path, 'PUT', 'Bearer ' . $accessToken, $data);
	}

	/** Send HTTP PATCH Request + DATA */
	private function sendHttpPatch($path, $accessToken, $data) // TODO: Header "If-Match" is REQUIRED (ETag to update only if not changed.)
	{
		return $this->sendRequest($path, 'PATCH', 'Bearer ' . $accessToken, $data);
	}

	/** Send HTTP DELETE Request */
	private function sendHttpDelete($path, $accessToken)
	{
		return $this->sendRequest($path, 'DELETE', 'Bearer ' . $accessToken, NULL);
	}

	/** Send HTTP OPTIONS Request */
	private function sendHttpOptions($path, $accessToken)
	{
		return $this->sendRequest($path, 'OPTIONS', 'Bearer ' . $accessToken, NULL);
	}


	// #################
	// ## AUXILIARY ##
	// #############

	/** Translate Sort/Filter/Page/Limit data to Query URI
	 * @param	string		$sort		// Sort query parameter (https://docs.api.dotypos.com/api-reference/general/sort)
	 * @param	string		$filter		// Filter query parameter (https://docs.api.dotypos.com/api-reference/general/filter)
	 * @param	int			$page		// Paging query parameter / Page (https://docs.api.dotypos.com/api-reference/general/paging)
	 * @param	int			$limit		// Paging query parameter / Limit (see $page var)
	 * 
	 * @return	string		$queryURI
	 */
	public function translateSFPL(string $sort = "", string $filter = "", int $page = 1, int $limit = 100)
	{
		$queryParams = array();
		if(!empty($sort))	$queryParams ['sort']	= $sort;
		if(!empty($filter))	$queryParams ['filter']	= $filter;
		if(!empty($page) && $page >= 1)	$queryParams ['page']	= $page;
		if(!empty($limit) && $limit >= 1 && $limit <= 100)	$queryParams ['limit']	= $limit;
		$queryURI = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
		return empty($queryURI) ? "" : "/?" . $queryURI;
	}

	/** GET Table ID Array
	 * @return	Array		$tables
	 */
	public function getTables()
	{
		return $this->tables;
	}


	// #####################
	// ## AUTHORIZATION ##
	// #################

	public function getAccessToken()
	{
		if(empty($this->accessToken) || $this->accessToken == NULL)
		{
			$result = $this->sendRequest('signin/token', 'POST', 'User ' . $this->refreshToken, array('_cloudId' => $this->cloudId));

			if(empty($result) || $result == NULL || !isset($result->accessToken))
				$this->accessToken = NULL;
			else
				$this->accessToken = $result->accessToken;
		}

		return $this->accessToken;
	}


	// ##############
	// ## BRANCH ##
	// ##########

	// BRANCH FLAGS
	public const BRANCH_SUBSTITUTING_BRANCH = 0;
	public const BRANCH_REPLACED_BRANCH = 1;
	public const BRANCH_HIDE_STOCK = 8;
	public const BRANCH_HIDE_PRICES = 9;
	public const BRANCH_FREE_LICENSE = 10;

	// BRANCH SCHEMA
	// https://docs.api.dotypos.com/entity/branch
	public function BranchSchema($name = '', $id = NULL, $features = 0, $flags = 0, $deleted = FALSE, $display = TRUE)
	{
		return [
			'id'			=> $id,					// <integer>	// <F->	Branch ID - cannot be NULL in PUT/PATCH methods
			'_cloudId'		=> $this->cloudId,		// <integer>	// <-->	Cloud ID (https://docs.api.dotypos.com/entity/cloud)
			'created'		=> NULL,				// <timestamp>	// <FS>	Branch created date and time
			'deleted'		=> $deleted,			// <boolean>	// <FS>	Branch deleted - cannot be TRUE in POST/PUT/PATCH methods
			'display'		=> $display,			// <boolean>	// <FS>	Branch displayed
			'features'		=> $features,			// <long>		// <F->	Branch features (BITS)
			'flags'			=> $flags,				// <short>		// <F->	Branch flags (BITS)
			'name'			=> $name,				// <string>		// <-->	Branch name (max. lenght: 100)
			'versionDate'	=> NULL,				// <timestamp>	// <FS>	Last modification date and time
		];
	}

	/** Get All Branches for Cloud
	 * @param	string		$sfplTail	// Optional. See translateSFPL method for more information.
	 * @return	array|NULL				// Returns array of Branch objects
	 */
	public function getBranchList($sfplTail = "")
	{
		$path = "clouds/" . $this->cloudId . "/branches" . $sfplTail;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Get Branch (by branchId)
	 * @param	int			$branchId	// Branch ID
	 * @return	array|NULL				// Returns Branch object
	 */
	public function getBranch($branchId)
	{
		$path = "clouds/" . $this->cloudId . "/branches/" . $branchId;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}


	// ################
	// ## CATEGORY ##
	// ############

	// TODO ...
	// CATEGORY SCHEMA
	// https://docs.api.dotypos.com/entity/category
	public function CategorySchema()
	{
	}


	// #############
	// ## CLOUD ##
	// #########

	// CLOUD SCHEMA
	// https://docs.api.dotypos.com/entity/cloud
	public function CloudSchema($name = '', $id = NULL, $deleted = FALSE, $expired = FALSE, $restricted = FALSE)
	{
		return [
			'id'			=> $id,					// <integer>	// <F->	Cloud ID - cannot be null in PUT/PATCH methods
			'1ClickId'		=> NULL,				// <integer>	// <F->	1Click ID
			'_companyId'	=> NULL,				// <long>		// <F->	Company ID
			'country'		=> NULL,				// <string>		// <-->	Country code (max. lenght: 3)
			'deleted'		=> $deleted,			// <boolean>	// <FS>	Cloud deleted - cannot be true in POST/PUT/PATCH methods
			'expired'		=> $expired,			// <boolean>	// <F->	Cloud expired
			'name'			=> $name,				// <string>		// <FS>	Cloud name (max. lenght: 255)
			'restricted'	=> $restricted,			// <boolean>	// <F->	Cloud restricted
			'segment'		=> NULL,				// <string>		// <-->	Cloud segment (max. lenght: 100)
		];
	}

	/** Get Cloud List
	 * @param	string		$sfplTail	// Optional. See translateSFPL method for more information.
	 * @return	array|NULL				// Returns array of Cloud objects
	 */
	public function getCloudList($sfplTail = "")
	{
		$path = "clouds" . $sfplTail;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Get Cloud (by cloudId)
	 * @param	int			$cloudId	// Cloud ID
	 * @return	array|NULL				// Returns Cloud object
	 */
	public function getCloud($cloudId)
	{
		$path = "clouds/" . $cloudId;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}


	// ################
	// ## CUSTOMER ##
	// ############

	// CUSTOMER SCHEMA
	// https://docs.api.dotypos.com/entity/customer
	public function CustomerSchema($firstname, $lastname, $email, $phone, $note = '', $internalNote = '')
	{
		return [
		//	'id'			=> NULL,				// <long>		// <F->	Customer ID - cannot be NULL in PUT/PATCH methods
			'_cloudId'		=> $this->cloudId,		// <integer>	// <-->	Cloud (https://docs.api.dotypos.com/entity/cloud)
		//	'_discountGroupId'	=> NULL,			// <long>		// <F->	Discount group
		//	'_sellerId'		=> NULL,				// <long>		// <F->	Seller
			'addressLine1'	=> "",				// <string> 180	// <F->	Address line 1
		//	'addressLine2'	=> "",					// <string> 180	// <F->	Address line 2
			'barcode'		=> "",				// <string>  50	// <F->	Bar code
		//	'city'			=> "",					// <string> 255	// <F->	City
			'companyId'		=> "",				// <string> 255	// <F->	Customer company ID (CZ: ICO, PL: REGON)
			'companyName'	=> "",				// <string> 180	// <FS>	Customer company name [1]
		//	'country'		=> "",					// <string>  10	// <F->	Country code
		//	'created'		=> NULL,				// <timestamp>	// <FS>	Customer created date and time
			'deleted'		=> false,				// <boolean>	// <FS>	Customer deleted - cannot be TRUE in POST/PUT/PATCH methods
			'display'		=> true,				// <boolean>	// <FS>	Customer displayed
			'email'			=> $email,				// <string> 100	// <F->	E-mail address
		//	'expireDate'	=> NULL,				// <timestamp>	// <FS>	Customer expire date and time
		//	'externalId'	=> "",					// <string> 256	// <F->	External ID
			'firstName'		=> $firstname,			// <string> 180	// <FS>	First name [1]
			'headerPrint'	=> "",				// <string> 256	// <-->	Header for printing
			'hexColor'		=> "#000000",				// <string>   7	// <-->	Customer color
			'internalNote'	=> $internalNote,		// <string>1000	// <-->	Internal note
			'lastName'		=> $lastname,			// <string> 180	// <FS>	Last name [1]
		//	'modifiedBy'	=> "",					// <string>  32	// <-->	Customer modified by
			'note'			=> $note,				// <string> 500	// <-->	Customer note
			'phone'			=> $phone,				// <string>  20	// <F->	Phone
			'points'		=> 0.0,				// <double>		// <F->	Customer points
			'tags'			=> array(),				// <string> 255	// <F->	Tags for a customer
			'vatId'			=> "",				// <string> 255	// <-->	Customer VAT ID (CZ: DIČ, PL: NIP). Validation regex.
		//	'versionDate'	=> NULL,				// <timestamp>	// <FS>	Last modification date and time
			'zip'			=> "",				// <string>  20	// <F->	ZIP code
		];
		// [1] Properties 'firstName', 'lastName' and 'companyName' must not be blank. At least one of these properties must contain a non-blank value!
	}

	/** Get All Customers for Cloud
	 * @param	string		$sfplTail	// Optional. See translateSFPL method for more information.
	 * @return	array|NULL				// Returns array of Customer objects
	 */
	public function getCustomerList($sfplTail = "")
	{
		$path = "clouds/" . $this->cloudId . "/customers" . $sfplTail;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Get Customer (by customerId)
	 * @param	int			$customerId	// Customer ID
	 * @return	array|NULL				// Returns Customer object
	 */
	public function getCustomer($customerId)
	{
		$path = "clouds/" . $this->cloudId . "/customers/" . $customerId;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Create Customers (from array of Customer objects)
	 * @param	array		$data		// Array of Customer objects
	 * @return	array|NULL				// Returns array of Customer objects
	 */
	public function createCustomers($data)
	{
		$path = "clouds/" . $this->cloudId . "/customers";
		return $this->sendHttpPost($path, $this->getAccessToken(), $data);
	}

	/** Delete Customer (by customerId)
	 * @param	int			$customerId	// Customer ID
	 * @return	array|NULL				// Returns Customer object
	 */
	public function deleteCustomer($customerId)
	{
		$path = "clouds/" . $this->cloudId . "/customers/" . $customerId;
		return $this->sendHttpDelete($path, $this->getAccessToken());
	}


	// ###################
	// ## RESERVATION ##
	// ###############

	// RESERVATION SCHEMA
	// https://docs.api.dotypos.com/entity/reservation
	public function ReservationSchema($tableId, $seats, $startDate, $endDate, $customerId = 0, $employeeId = 0, $note = '', $flags = 0, $status = 'CONFIRMED')
	{
		return [
		//	'id'			=> NULL,				// <long>		// <F->	Reservation ID - cannot be NULL in PUT/PATCH methods
			'_branchId'		=> $this->branchId,		// <integer>	// <F->	Branch (https://docs.api.dotypos.com/entity/branch)
			'_cloudId'		=> $this->cloudId,		// <integer>	// <-->	Cloud (https://docs.api.dotypos.com/entity/cloud)
			'_customerId'	=> $customerId,			// <long>		// <F->	Customer (https://docs.api.dotypos.com/entity/customer)
			'_employeeId'	=> $employeeId,			// <long>		// <F->	Employee (https://docs.api.dotypos.com/entity/employee)
			'_tableId'		=> $tableId,			// <long>		// <F->	Table (https://docs.api.dotypos.com/entity/table)
			'created'		=> NULL,				// <timestamp>	// <FS>	Reservation created date and time
			'startDate'		=> $startDate,			// <timestamp>	// <FS>	Start date and time
			'endDate'		=> $endDate,			// <timestamp>	// <FS>	End date and time
			'flags'			=> $flags,				// <integer>	// <F->	Reservation flags (BITS)
			'note'			=> $note,				// <string>		// <-->	Reservation note
			'seats'			=> $seats,				// <short>		// <-->	Number of table seats - minimum value is 1, maximum value must be less or equal to the number of seats in the Table entity (Table.seats)
			'status'		=> $status,				// <enum>		// <-->	Reservation status [NEW, CONFIRMED, CANCELLED]
			'versionDate'	=> NULL,				// <timestamp>	// <FS>	Last modification date and time
		];
	}

	/** Get All Reservations for Cloud
	 * @param	string		$sfplTail	// Optional. See translateSFPL method for more information.
	 * @return	array|NULL				// Returns array of Reservation objects
	 */
	public function getReservationList($sfplTail = "")
	{
		$path = "clouds/" . $this->cloudId . "/reservations" . $sfplTail;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Get Reservation (by reservationId)
	 * @param	int			$reservationId	// Reservation ID
	 * @return	array|NULL					// Returns Reservation object
	 */
	public function getReservation($reservationId)
	{
		$path = "clouds/" . $this->cloudId . "/reservations/" . $reservationId;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Create Reservations (from array of Reservation objects)
	 * @param	array		$data		// Array of Reservation objects
	 * @return	array|NULL				// Returns array of Reservation objects
	 */
	public function createReservations($data)
	{
		$path = "clouds/" . $this->cloudId . "/reservations";
		return $this->sendHttpPost($path, $this->getAccessToken(), $data);
	}

	/** Delete Reservation (by reservationId)
	 * @param	int			$reservationId	// Reservation ID
	 * @return	array|NULL					// Returns Reservation object
	 */
	public function deleteReservation($reservationId)
	{
		$path = "clouds/" . $this->cloudId . "/reservations/" . $reservationId;
		return $this->sendHttpDelete($path, $this->getAccessToken());
	}


	// ###############
	// ## PRODUCT ##
	// ###########

	// PRODUCT SCHEMA
	// https://docs.api.dotypos.com/entity/product
	public function ProductSchema($tableId, $seats, $startDate, $endDate, $customerId = 0, $employeeId = 0, $note = '', $flags = 0)
	{
		/*return [
		//	'id'			=> NULL,				// <long>		// <F->	Reservation ID - cannot be NULL in PUT/PATCH methods
			'_branchId'		=> $this->branchId,		// <integer>	// <F->	Branch (https://docs.api.dotypos.com/entity/branch)
			'_cloudId'		=> $this->cloudId,		// <integer>	// <-->	Cloud (https://docs.api.dotypos.com/entity/cloud)
			'_customerId'	=> $customerId,			// <long>		// <F->	Customer (https://docs.api.dotypos.com/entity/customer)
			'_employeeId'	=> $employeeId,			// <long>		// <F->	Employee (https://docs.api.dotypos.com/entity/employee)
			'_tableId'		=> $tableId,			// <long>		// <F->	Table (https://docs.api.dotypos.com/entity/table)
			'created'		=> NULL,				// <timestamp>	// <FS>	Reservation created date and time
			'startDate'		=> $startDate,			// <timestamp>	// <FS>	Start date and time
			'endDate'		=> $endDate,			// <timestamp>	// <FS>	End date and time
			'flags'			=> $flags,				// <integer>	// <F->	Reservation flags (BITS)
			'note'			=> $note,				// <string>		// <-->	Reservation note
			'seats'			=> $seats,				// <short>		// <-->	Number of table seats - minimum value is 1, maximum value must be less or equal to the number of seats in the Table entity (Table.seats)
			'status'		=> NULL,				// <enum>		// <-->	Reservation status [NEW, CONFIRMED, CANCELLED]
			'versionDate'	=> NULL,				// <timestamp>	// <FS>	Last modification date and time
		];*/
	}

	/** Get All Products for Cloud
	 * @param	string		$sfplTail	// Optional. See translateSFPL method for more information.
	 * @return	array|NULL				// Returns array of Product objects
	 */
	public function getProductList($sfplTail = "")
	{
		$path = "clouds/" . $this->cloudId . "/products" . $sfplTail;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Get Product (by productId)
	 * @param	int			$productId		// Product ID
	 * @return	array|NULL					// Returns Product object
	 */
	public function getProduct($productId)
	{
		$path = "clouds/" . $this->cloudId . "/products/" . $productId;
		return $this->sendHttpGet($path, $this->getAccessToken());
	}

	/** Create Product (from array of Product objects)
	 * @param	array		$data		// Array of Product objects
	 * @return	array|NULL				// Returns array of Product objects
	 */
	public function createProduct($data)
	{
		$path = "clouds/" . $this->cloudId . "/products";
		return $this->sendHttpPost($path, $this->getAccessToken(), $data);
	}


	// #################
	// ## WAREHOUSE ##
	// #############

	/** Stockup to warehouse
	 * @param	array		$data		// Array of Product objects
	 * @return	array|NULL				// Returns array of Product objects
	 */
	public function stockupToWarehouse($_supplierId, $invoiceNumber, $note, $updatePurchasePrice, $items)
	{
		$path = "clouds/" . $this->cloudId . "/warehouses/" . $this->warehouseId . "/stockups";
		$data = [
			'_supplierId'			=> $_supplierId,
			'invoiceNumber'			=> $invoiceNumber,
			'note'					=> $note,
			'updatePurchasePrice'	=> $updatePurchasePrice,
			'items'					=> $items,
		];
		return $this->sendHttpPost($path, $this->getAccessToken(), $data);
	}



	// ############################################################################################

}
