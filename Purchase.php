<?php

namespace App\Entity\Purchases\Logic;
#Models
use App\Models\Sales\Serie;
use App\Models\Purchases\Purchase;
use App\Models\Purchases\PurchaseDetail;
use App\Models\Purchases\PurPayment;
use App\Models\Companies\Supplier;
use App\Models\Sales\SaleState;
use App\Models\Warehouse\Warehouse;
use App\Models\Companies\Employee;
use App\Models\Warehouse\Transfer;
use App\Models\Warehouse\Kardex;
use App\Models\Warehouse\WarehouseProduct;
#Providers
use DB;
use Illuminate\Support\Facades\Redis;
#External Logic
use App\Entity\Sales\Logic\SerieLogic;
use App\Entity\Warehouse\Logic\AllotmentLogic;
use App\Entity\Warehouse\Logic\SerialLogic;

class PurchaseLogic
{
    #constants
    const ITEMS_PAGINATOR = 10;
    #protected vars
    protected $jwtUser = null;
    #construct
    public function __construct()
    {
        $this->jwtUser = $this->getUserJwt();
    }
    #private functions
    private function getUserJwt()
    {
        $jwtKey = \JWTAuth::getToken();
        if (empty($jwtKey)) throw new \Exception('Token vacío');
        $jwtUser = Redis::get($jwtKey);
        if (empty($jwtUser)) throw new \Exception('Usuario no existe');
        $jwtUser = json_decode($jwtUser);
        return $jwtUser;
    }
    #public functions
	public function postCreate($params = [])
	{
        $purchase = null;
        $object = [];
        if (!is_null($this->jwtUser)) {
            if (isset($params['type_document'])) {
                $serieLogic = new SerieLogic();
                $serie = $serieLogic->getSerieByTypeDocument($params['type_document'], $this->jwtUser, true);
                // CREATE OBJECT
                $object['sal_series_id'] = $serie->id;
                $object['serie'] = $serie->serie;
                $object['number'] = $serie->number;
                $object['com_companies_id'] = $this->jwtUser->cms_companies_id;
                $object['com_employees_id'] = $this->jwtUser->id;
                $object['sal_type_documents_id'] = $serie->sal_type_documents_id;
                $object['war_warehouses_id'] = $params['warehouseId'];
                $object['pur_suppliers_id'] = $params['supplierId'];
                $object['purchase_serie'] = $params['purchaseSerie'];
                $object['purchase_number'] = $params['purchaseNumber'];
                $object['currency'] = $params['currency'];
                $object['date_purchase'] = date("Y-m-d", strtotime($params['documentDate']));
                $object['commentary'] = $params['commentary'];
                $object['type_currency'] = $params['typeCurrency'];
                $object['accounting_date'] = date("Y-m-d", strtotime($params['accountingDate']));
                $object['type_purchase'] = $params['typeDocument'];
                $object['amount'] = $params['amount'];
                $object['subtotal'] = $params['subtotal'];
                $object['op_inafectas'] = $params['op_inafectas'];
                $object['op_exoneradas'] = $params['op_exoneradas'];
                $object['op_icbper'] = $params['op_icbper'];
                $object['taxes'] = $params['taxes'];
                //descuento general
                /* $object['discounts'] = $params['op_exoneradas']; */

                // create purchase
                $purchase = Purchase::create($object);
                Transfer::find($params['transferId'])->update(['pur_documents_id' => $purchase->id]);
                if (!is_null($purchase) && isset($params['details'])) {
                    foreach ($params['details'] as $key => $value) {
                        // create purchase detail
                        $objectDetail = [];
                        $objectDetail['pur_documents_id'] = $purchase->id;
                        $objectDetail['quantity'] = $value['quantity'];
                        $objectDetail['price'] = $value['priceUnit'];
                        $objectDetail['war_products_id'] = $value['id'];
                        $objectDetail['discount'] = $value['discount'];
                        $objectDetail['total'] = $value['total'];
                        PurchaseDetail::create($objectDetail);

                        if ($params['productIncome'] == true) {
                            $kardex = Kardex::select(Kardex::TABLE_NAME . '.*')
                                ->where(Kardex::TABLE_NAME . '.company_id', $this->jwtUser->company_id)
                                ->where(Kardex::TABLE_NAME . '.warehouse_id', (int)$params['warehouseId'])
                                ->where(Kardex::TABLE_NAME . '.product_id', (int)$value['id'])
                                ->where(Kardex::TABLE_NAME . '.document_number', (int)$params['number'])
                                ->where(Kardex::TABLE_NAME . '.document_id', (int)$params['typeDocumentId'])
                                ->first();
                        
                            $prevKardex = Kardex::select(Kardex::TABLE_NAME . '.*')
                                ->where(Kardex::TABLE_NAME . '.id', '<', $kardex->id)
                                ->where(Kardex::TABLE_NAME . '.company_id', $this->jwtUser->company_id)
                                ->where(Kardex::TABLE_NAME . '.warehouse_id', (int)$params['warehouseId'])
                                ->where(Kardex::TABLE_NAME . '.product_id', (int)$value['id'])
                                ->orderBy(Kardex::TABLE_NAME . '.id', 'DESC')
                                ->first();
                            
                            
                            $warProduct = WarehouseProduct::select(WarehouseProduct::TABLE_NAME . '.stock')
                                ->where(WarehouseProduct::TABLE_NAME . '.warehouse_id', (int)$params['warehouseId'])
                                ->where(WarehouseProduct::TABLE_NAME . '.product_id', (int)$value['id'])
                                ->first();

                            //Actualizar kardex
                            if(!is_null($prevKardex)) {
                                $totalNow = $prevKardex->total_now + ($value['priceUnit'] * $value['quantity']);
                            } else {
                                $totalNow = ($value['priceUnit'] * $value['quantity']);
                            }
                            
                            $updateParams = [
                                'price' => $value['priceUnit'],
                                'quantity_now' => $warProduct->stock,
                                'total_now' => $totalNow,
                                'average_price' => $totalNow/$warProduct->stock,
                            ];
                            Kardex::where(Kardex::TABLE_NAME . '.id', $kardex->id)
                                ->update($updateParams);

                            if (isset($params['allotments']) && count($params['allotments']) > 0) {
                                $allotmentLogic = new AllotmentLogic();
                                $allotmensDataSend = [];
                                foreach ($params['allotments'] as $key => $value) {
                                    if ((int)$value['quantity'] > 0) {
                                        $allotmentHeader = [
                                            "transferId" => $params['transferId'],
                                            "warehouseId" => $params['warehouseId'],
                                            "serie" => $params['serie'],
                                            "number" => $params['number'],
                                            "code" => $value['code'],
                                            "details" => []
                                        ];
                                        $allotmentBody = [
                                            "war_products_id" => isset($value['productId'])?$value['productId']:$value['war_products_id'],
                                            "quantity" => $value['quantity'],
                                            "description" => $value['description'],
                                            "expiration_date" => isset($value['expirationDate']) ? $value['expirationDate'] : null,
                                            "json_data" => []
                                        ];
                                        array_push($allotmentHeader['details'], $allotmentBody);
                                        array_push($allotmensDataSend, $allotmentHeader);
                                    }
                                }
                            }
                        }
                    }
                    if ($params['productIncome'] == true) {
                        if (isset($params['allotments']) && count($params['allotments']) > 0) {
                            foreach ($allotmensDataSend as $value) {
                                $allotmentLogic->postCreate($this->jwtUser, $value);
                            }
                        }
                        if (isset($params['serials']) && count($params['serials']) > 0) {
                            $serialLogic = new SerialLogic();
                            foreach ($params['serials'] as $key => $value) {
                                $serialLogic->postCreate($this->jwtUser, $value);
                            }
                        }
                    }
                }
            }
        }
        return $purchase;
    }
    public function getList($params = [])
    {
        $itemsForPaginator = isset($data['per_page'])? $data['per_page'] : self::ITEMS_PAGINATOR;
        $purchaseList = [];
        if (!is_null($this->jwtUser)) {
            $purchaseList = Purchase::join(Supplier::TABLE_NAME, Supplier::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.pur_suppliers_id')
                ->join(SaleState::TABLE_NAME, SaleState::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.sal_sales_states_id')
                ->join(Warehouse::TABLE_NAME, Warehouse::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.war_warehouses_id')
                ->join(Employee::TABLE_NAME, Employee::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.com_employees_id')
                ->select(Purchase::TABLE_NAME . '.id',
                    Purchase::TABLE_NAME . '.serie',
                    Purchase::TABLE_NAME . '.purchase_serie',
                    Purchase::TABLE_NAME . '.number',
                    Purchase::TABLE_NAME . '.purchase_number',
                    Purchase::TABLE_NAME . '.currency',
                    Purchase::TABLE_NAME . '.amount',
                    Purchase::TABLE_NAME . '.subtotal',
                    Purchase::TABLE_NAME . '.taxes',
                    Purchase::TABLE_NAME . '.date_purchase',
                    Purchase::TABLE_NAME . '.created_at',
                    Purchase::TABLE_NAME . '.updated_at',
                    Purchase::TABLE_NAME . '.sal_sales_states_id',
                    Supplier::TABLE_NAME . '.dni as suppliers_dni',
                    Supplier::TABLE_NAME . '.name as suppliers_name',
                    Supplier::TABLE_NAME . '.lastname as suppliers_lastname',
                    Supplier::TABLE_NAME . '.flag_type_person as suppliers_flag_type_person',
                    Supplier::TABLE_NAME . '.rz_social as suppliers_rz_social',
                    Supplier::TABLE_NAME . '.ruc as suppliers_ruc',
                    SaleState::TABLE_NAME . '.name as state_name',
                    Warehouse::TABLE_NAME . '.name as warehouse_name',
                    Employee::TABLE_NAME . '.name as employee_name',
                    Employee::TABLE_NAME . '.lastname as employee_lastname')
                ->whereNull(Purchase::TABLE_NAME . '.deleted_at')
                ->where(Purchase::TABLE_NAME . '.com_companies_id', $this->jwtUser->cms_companies_id);
            if (isset($params['initDate']) && isset($params['endingDate'])) {
				if (strlen($params['initDate']) > 10) {
					$purchaseList = $purchaseList->whereBetween(Purchase::TABLE_NAME.'.created_at', array(date($params['initDate']), date($params['endingDate'])));
				} else {
					$purchaseList = $purchaseList->whereBetween(Purchase::TABLE_NAME.'.created_at', array(date($params['initDate'] . " 00:00:00"), date($params['endingDate'] . " 23:59:59")));
				}
            }
            if (isset($data['warehouse_id']) && ((int)$data['warehouse_id'] !== 0)) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', (int)$data['warehouse_id']);
            } elseif (isset($data['warehouse_id']) && ((int)$data['warehouse_id'] === 0)) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', @(int)$jwtUser->war_warehouses_id);
            } elseif (!isset($data['warehouse_id'])) {
                // $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', @(int)$jwtUser->war_warehouses_id);
            }
            if (isset($data['state'])) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.sal_sales_states_id', (int)$data['state']);
            }
            if (isset($data['sal_type_payments_id'])) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.sal_type_payments_id', (int)$data['sal_type_payments_id']);
            }
            // role logic
            $conditionLogic = true;
            foreach ($this->jwtUser->roles_config as $keyRc => $valueRc) {
				if ($valueRc->apps_id === 1 && $valueRc->roles_id === 1) {
					$conditionLogic = false;
				}
            }
            if ($conditionLogic) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME . '.com_employees_id', $this->jwtUser->id);
            }
            if (isset($data['page'])) {
                $purchaseList = $purchaseList->orderBy(Purchase::TABLE_NAME . '.created_at', 'DESC')->paginate($itemsForPaginator);
            } else {
                //refactorizar
                $purchaseList = $purchaseList->orderBy(Purchase::TABLE_NAME . '.created_at', 'DESC')->paginate(1000000);
            }
            $purchaseList =  $purchaseList->toArray();
        }
        return $purchaseList;
    }

    public function getListOfDueBills($params = [])
    {
        $itemsForPaginator = isset($data['per_page'])? $data['per_page'] : self::ITEMS_PAGINATOR;
        $purchaseList = [];
        if (!is_null($this->jwtUser)) {
            $purchaseList = Purchase::join(Supplier::TABLE_NAME, Supplier::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.pur_suppliers_id')
                ->join(Warehouse::TABLE_NAME, Warehouse::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.war_warehouses_id')
                ->join(Employee::TABLE_NAME, Employee::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.com_employees_id')
                ->select(Purchase::TABLE_NAME . '.id',
                    Purchase::TABLE_NAME . '.serie',
                    Purchase::TABLE_NAME . '.purchase_serie',
                    Purchase::TABLE_NAME . '.number',
                    Purchase::TABLE_NAME . '.purchase_number',
                    Purchase::TABLE_NAME . '.currency',
                    Purchase::TABLE_NAME . '.amount',
                    Purchase::TABLE_NAME . '.subtotal',
                    Purchase::TABLE_NAME . '.taxes',
                    Purchase::TABLE_NAME . '.date_purchase',
                    Purchase::TABLE_NAME . '.created_at',
                    Purchase::TABLE_NAME . '.updated_at',
                    Purchase::TABLE_NAME . '.sal_sales_states_id',
                    Purchase::TABLE_NAME . '.pur_suppliers_id',
                    Supplier::TABLE_NAME . '.dni as suppliers_dni',
                    Supplier::TABLE_NAME . '.name as suppliers_name',
                    Supplier::TABLE_NAME . '.lastname as suppliers_lastname',
                    Supplier::TABLE_NAME . '.flag_type_person as suppliers_flag_type_person',
                    Supplier::TABLE_NAME . '.rz_social as suppliers_rz_social',
                    Supplier::TABLE_NAME . '.ruc as suppliers_ruc',
                    Warehouse::TABLE_NAME . '.name as warehouse_name',
                    Employee::TABLE_NAME . '.name as employee_name',
                    Employee::TABLE_NAME . '.lastname as employee_lastname')
                ->with('purPayments')
                ->whereNull(Purchase::TABLE_NAME . '.deleted_at')
                ->where(Purchase::TABLE_NAME . '.com_companies_id', $this->jwtUser->cms_companies_id)
                ->where(Purchase::TABLE_NAME.'.sal_sales_states_id', SaleState::STATE_CONVERTED);
            if (isset($params['initDate']) && isset($params['endingDate'])) {
				if (strlen($params['initDate']) > 10) {
					$purchaseList = $purchaseList->whereBetween(Purchase::TABLE_NAME.'.created_at', array(date($params['initDate']), date($params['endingDate'])));
				} else {
					$purchaseList = $purchaseList->whereBetween(Purchase::TABLE_NAME.'.created_at', array(date($params['initDate'] . " 00:00:00"), date($params['endingDate'] . " 23:59:59")));
				}
            }
            if (isset($data['warehouse_id']) && ((int)$data['warehouse_id'] !== 0)) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', (int)$data['warehouse_id']);
            } elseif (isset($data['warehouse_id']) && ((int)$data['warehouse_id'] === 0)) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', @(int)$jwtUser->war_warehouses_id);
            } elseif (!isset($data['warehouse_id'])) {
                // $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', @(int)$jwtUser->war_warehouses_id);
            }
            if (isset($data['sal_type_payments_id'])) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.sal_type_payments_id', (int)$data['sal_type_payments_id']);
            }
            // role logic
            $conditionLogic = true;
            foreach ($this->jwtUser->roles_config as $keyRc => $valueRc) {
				if ($valueRc->apps_id === 1 && $valueRc->roles_id === 1) {
					$conditionLogic = false;
				}
            }
            if ($conditionLogic) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME . '.com_employees_id', $this->jwtUser->id);
            }
            if (isset($data['page'])) {
                $purchaseList = $purchaseList->orderBy(Purchase::TABLE_NAME . '.created_at', 'DESC')->paginate($itemsForPaginator);
            } else {
                //refactorizar
                $purchaseList = $purchaseList->orderBy(Purchase::TABLE_NAME . '.created_at', 'DESC')->paginate(1000000);
            }
            $purchaseList =  $purchaseList->toArray();
        }
        return $purchaseList;
    }

    public function getListOfPayBills($params = [])
    {
        $itemsForPaginator = isset($data['per_page'])? $data['per_page'] : self::ITEMS_PAGINATOR;
        $purchaseList = [];
        if (!is_null($this->jwtUser)) {
            $purchaseList = Purchase::join(Supplier::TABLE_NAME, Supplier::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.pur_suppliers_id')
                ->join(Warehouse::TABLE_NAME, Warehouse::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.war_warehouses_id')
                ->join(Employee::TABLE_NAME, Employee::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.com_employees_id')
                ->select(Purchase::TABLE_NAME . '.id',
                    Purchase::TABLE_NAME . '.serie',
                    Purchase::TABLE_NAME . '.purchase_serie',
                    Purchase::TABLE_NAME . '.number',
                    Purchase::TABLE_NAME . '.purchase_number',
                    Purchase::TABLE_NAME . '.currency',
                    Purchase::TABLE_NAME . '.amount',
                    Purchase::TABLE_NAME . '.subtotal',
                    Purchase::TABLE_NAME . '.taxes',
                    Purchase::TABLE_NAME . '.date_purchase',
                    Purchase::TABLE_NAME . '.created_at',
                    Purchase::TABLE_NAME . '.updated_at',
                    Purchase::TABLE_NAME . '.sal_sales_states_id',
                    Purchase::TABLE_NAME . '.pur_suppliers_id',
                    Supplier::TABLE_NAME . '.dni as suppliers_dni',
                    Supplier::TABLE_NAME . '.name as suppliers_name',
                    Supplier::TABLE_NAME . '.lastname as suppliers_lastname',
                    Supplier::TABLE_NAME . '.flag_type_person as suppliers_flag_type_person',
                    Supplier::TABLE_NAME . '.rz_social as suppliers_rz_social',
                    Supplier::TABLE_NAME . '.ruc as suppliers_ruc',
                    Warehouse::TABLE_NAME . '.name as warehouse_name',
                    Employee::TABLE_NAME . '.name as employee_name',
                    Employee::TABLE_NAME . '.lastname as employee_lastname')
                ->with('purPayments')
                ->whereNull(Purchase::TABLE_NAME . '.deleted_at')
                ->where(Purchase::TABLE_NAME . '.com_companies_id', $this->jwtUser->cms_companies_id)
                ->where(Purchase::TABLE_NAME.'.sal_sales_states_id', SaleState::STATE_CLOSED);
            if (isset($params['initDate']) && isset($params['endingDate'])) {
				if (strlen($params['initDate']) > 10) {
					$purchaseList = $purchaseList->whereBetween(Purchase::TABLE_NAME.'.created_at', array(date($params['initDate']), date($params['endingDate'])));
				} else {
					$purchaseList = $purchaseList->whereBetween(Purchase::TABLE_NAME.'.created_at', array(date($params['initDate'] . " 00:00:00"), date($params['endingDate'] . " 23:59:59")));
				}
            }
            if (isset($data['warehouse_id']) && ((int)$data['warehouse_id'] !== 0)) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', (int)$data['warehouse_id']);
            } elseif (isset($data['warehouse_id']) && ((int)$data['warehouse_id'] === 0)) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', @(int)$jwtUser->war_warehouses_id);
            } elseif (!isset($data['warehouse_id'])) {
                // $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.war_warehouses_id', @(int)$jwtUser->war_warehouses_id);
            }
            if (isset($data['sal_type_payments_id'])) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME.'.sal_type_payments_id', (int)$data['sal_type_payments_id']);
            }
            // role logic
            $conditionLogic = true;
            foreach ($this->jwtUser->roles_config as $keyRc => $valueRc) {
				if ($valueRc->apps_id === 1 && $valueRc->roles_id === 1) {
					$conditionLogic = false;
				}
            }
            if ($conditionLogic) {
                $purchaseList = $purchaseList->where(Purchase::TABLE_NAME . '.com_employees_id', $this->jwtUser->id);
            }
            if (isset($data['page'])) {
                $purchaseList = $purchaseList->orderBy(Purchase::TABLE_NAME . '.created_at', 'DESC')->paginate($itemsForPaginator);
            } else {
                //refactorizar
                $purchaseList = $purchaseList->orderBy(Purchase::TABLE_NAME . '.created_at', 'DESC')->paginate(1000000);
            }
            $purchaseList =  $purchaseList->toArray();
        }
        return $purchaseList;
    }

    public function getById($purchaseId, $itemsView = true)
    {
        $purchase = null;
        if (!is_null($this->jwtUser)) {
            $purchase = Purchase::whereNull(Purchase::TABLE_NAME . '.deleted_at')
                ->where(Purchase::TABLE_NAME . '.com_companies_id', $this->jwtUser->cms_companies_id);
            if ($itemsView) {
                $purchase = $purchase->with('items.product:id,name,code,auto_barcode,description,url_image')
                    ->with('purPaymentsComplete');
            }
            $purchase = $purchase->find($purchaseId);
        }
        return $purchase;
    }

    public function getByIdForIncome($purchaseId)
    {
        $purchase = null;
        if (!is_null($this->jwtUser)) {
            $purchase = Purchase::join(Supplier::TABLE_NAME, Supplier::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.pur_suppliers_id')
                ->join(Warehouse::TABLE_NAME, Warehouse::TABLE_NAME . '.id', '=',
                    Purchase::TABLE_NAME . '.war_warehouses_id')
                ->select(Purchase::TABLE_NAME . '.id',
                    Purchase::TABLE_NAME . '.purchase_serie',
                    Purchase::TABLE_NAME . '.purchase_number',
                    Purchase::TABLE_NAME . '.pur_suppliers_id',
                    Purchase::TABLE_NAME . '.war_warehouses_id',
                    Supplier::TABLE_NAME . '.dni as suppliers_dni',
                    Supplier::TABLE_NAME . '.name as suppliers_name',
                    Supplier::TABLE_NAME . '.lastname as suppliers_lastname',
                    Supplier::TABLE_NAME . '.flag_type_person as suppliers_flag_type_person',
                    Supplier::TABLE_NAME . '.rz_social as suppliers_rz_social',
                    Supplier::TABLE_NAME . '.ruc as suppliers_ruc',
                    Warehouse::TABLE_NAME . '.name as warehouse_name')
                        ->whereNull(Purchase::TABLE_NAME . '.deleted_at')
                ->where(Purchase::TABLE_NAME . '.com_companies_id', $this->jwtUser->cms_companies_id)
                ->with('itemsForIncome.product:id,brand_id,name,code,auto_barcode,description,url_image,allotment_type,price')
                ->find($purchaseId);
        }
        return $purchase;
    }

    public function updatePurchaseDetails($params = [])
    {
        $purchase = null;
        if (!is_null($this->jwtUser)) {
            if (isset($params['transferId'])) {
                // update transfer
                $transfer = Transfer::find($params['transferId']);
                if (!is_null($transfer)) {
                    $transfer->pur_documents_id = $params['purchaseId'];
                    $transfer->save();
                }
                // update purchase details
                if (isset($params['details'])) {
                    foreach ($params['details'] as $key => &$value) {
                        if ((float)$value['quantity'] > 0) {
                            $purchaseDetail = PurchaseDetail::find($value['purchaseDetailId']);
                            if (!is_null($purchaseDetail)) {
                                // update detail
                                $purchaseDetail->quantity_dispatch = $purchaseDetail->quantity_dispatch + (float)$value['quantity'];
                                if ($purchaseDetail->quantity_dispatch >= $purchaseDetail->quantity) {
                                    $purchaseDetail->quantity_dispatch = $purchaseDetail->quantity;
                                    $purchaseDetail->closed_at = date("Y-m-d H:i:s");
                                }
                                $json_documents = $purchaseDetail->json_documents;
                                if (is_null($json_documents)) {
                                    $json_documents = [];
                                }
                                $value['transferId'] = $params['transferId'];
                                $value['transferSerie'] = $transfer->serie;
                                $value['transferNumber'] = $transfer->number;
                                array_push($json_documents, $value);
                                $purchaseDetail->json_documents = $json_documents;
                                $purchaseDetail->save();
                            }
                        }
                    }
                }
                // update purchase
                $purchase = Purchase::find($params['purchaseId']);
                if (!is_null($purchase)) {
                    // search closed details
                    $count = PurchaseDetail::whereNull(PurchaseDetail::TABLE_NAME . '.deleted_at')
                        ->where(PurchaseDetail::TABLE_NAME . '.pur_documents_id', $purchase->id)
                        ->count();
                    $countClosed = PurchaseDetail::whereNull(PurchaseDetail::TABLE_NAME . '.deleted_at')
                        ->whereNotNull(PurchaseDetail::TABLE_NAME . '.closed_at')
                        ->where(PurchaseDetail::TABLE_NAME . '.pur_documents_id', $purchase->id)
                        ->count();
                    if ($count === $countClosed) {
                        $purchase->sal_sales_states_id = 10;
                        $purchase->closed_at = date("Y-m-d H:i:s");
                    } else {
                        $purchase->sal_sales_states_id = 11;
                    }
                    $purchase->save();
                }
            }
        }
        return $purchase;
    }

    public function updateById($purchaseId, $params = [])
    {
        $purchase = null;
        if (!is_null($this->jwtUser)) {
            $purchase = $this->getById($purchaseId, false);
            if (!is_null($purchase)) {
                $purchase->fill($params);
                $purchase->save();
                if (isset($params['items'])) {
                    foreach ($params['items'] as $key => $value) {
                        if (!is_null($value['id'])) {
                            $this->updateDetailById($value['id'], $value);
                        } else {
                            $this->postCreateDetail($purchaseId, $value);
                        }
                    }
                }
            }
        }
        return $purchase;
    }

    public function updateDetailById($detailId, $params = [])
    {
        $purchaseDetail = PurchaseDetail::find($detailId);
        if (!is_null($purchaseDetail)) {
            if (isset($params['id'])) {
                unset($params['id']);
            }
            if (isset($params['pur_documents_id'])) {
                unset($params['pur_documents_id']);
            }
            $purchaseDetail->fill($params);
            $purchaseDetail->save();
        }
        return $purchaseDetail;
    }

    public function postCreateDetail($purchaseId, $params = [])
    {
        if (isset($params['id'])) {
            unset($params['id']);
        }
        $params['pur_documents_id'] = $purchaseId;
        $purchaseDetail = PurchaseDetail::create($params);
        return $purchaseDetail;
    }

    public function postCreatePurPayment($params = [])
    {
        $purPayment = null;
        if (!is_null($this->jwtUser)) {
            // pur_suppliers_id
            if (isset($params['pur_documents_id'])) {
                $purchase = Purchase::with('purPayments')
                    ->find($params['pur_documents_id']);
                if (!is_null($purchase)) {
                    $amount_ = (float)$params['amount'];
                    foreach ($purchase->purPayments as $key => $value) {
                        $amount_ = $amount_ + $value->amount;
                    }
                    if ((float)$purchase->amount === $amount_) {
                        $params['com_employee_id'] = $this->jwtUser->id;
                        $purPayment = PurPayment::create($params);
                        // CLOSE PURCHASE
                        $purchase->sal_sales_states_id = SaleState::STATE_CLOSED;
                        $purchase->save();
                    } elseif ((float)$purchase->amount > $amount_) {
                        $params['com_employee_id'] = $this->jwtUser->id;
                        $purPayment = PurPayment::create($params);
                    }
                }
            }
        }
        return $purPayment;
    }

    public function deleteById($purchaseId)
    {
        $purchase = null;
        if (!is_null($this->jwtUser)) {
            $purchase = $this->getById($purchaseId, false);
            if (!is_null($purchase)) {
                $purchase->deleted_at = date("Y-m-d H:i:s");
                $purchase->flag_active = Purchase::STATE_INACTIVE;
                $purchase->save();
            }
        }
        return $purchase;
    }

    public function deleteDetailById($purchaseDetailId)
    {
        $purchaseDetail = PurchaseDetail::find($purchaseDetailId);
        if (!is_null($purchaseDetail)) {
            $purchaseDetail->deleted_at = date("Y-m-d H:i:s");
            $purchaseDetail->flag_active = PurchaseDetail::STATE_INACTIVE;
            $purchaseDetail->save();
        }
        return $purchaseDetail;
    }
}