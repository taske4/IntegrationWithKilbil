<?php


namespace Kilbil;

use Bitrix\Sale;

class Adapter
{
    private static $instance;

    private function __construct(){}

    private static function getInstance()
    {
        return self::$instance ? self::$instance : self::$instance = new self();
    }

    /**
     * @param array $productIDs
     * @return array
     */
    private function getProductKilbilCodes(array $productIDs)
    {
        $kilbilCodes = [];

        $arOrder  = ['SORT' => 'ASC'];
        $arFilter = [
            'IBLOCK_ID' => 16,
            'ID'        => $productIDs,
        ];
        $arSelectFields = ['IBLOCK_ID','ID','ACTIVE','NAME', 'PROPERTY_KILBIL_CODE'];
        $rsElements     = \CIBlockElement::GetList($arOrder, $arFilter, false, false, $arSelectFields);

        while($arElement = $rsElements->GetNext())
        {
            $kilbilCodes[$arElement['ID']] = $arElement['PROPERTY_KILBIL_CODE_VALUE'];
        }

        return $kilbilCodes;
    }

    /**
     * @return array|null
     */
    private function formatKilbilGoodsFromBasket()
    {
        $productKilbilCodes = $products = [];

        $basket = Sale\Basket::loadItemsForFUser(Sale\Fuser::getId(), \Bitrix\Main\Context::getCurrent()->getSite());

        foreach($basket as $basketItem) {
            $products[$basketItem->getProductId()] = [
                'code'             => null,
                'name'             => $basketItem->getField('NAME'),
                'price'            => $basketItem->getPrice(),
                'quantity'         => $basketItem->getQuantity(),
                'total'            => $basketItem->getFinalPrice(),
                'discounted_price' => $basketItem->getPrice(),
                'discounted_total' => $basketItem->getFinalPrice(),
            ];
        }

        $productKilbilCodes = $this->getProductKilbilCodes(array_keys($products));

        if (count($productKilbilCodes) > 0) {
            foreach($products as $productId => &$props)
            {
                if ($productKilbilCodes[$productId]) {
                    $props['code'] = $productKilbilCodes[$productId];
                } else {
                    unset($products[$productId]);
                }
            }

            return $products;
        } else {
            return null;
        }
    }

    private function updateBasket($goods, $billData)
    {
        $data = $tmp = [];

        foreach($billData as $item) {
            $tmp[$item['code']] = [
                'discounted_price' => $item['discounted_price'],
            ];
        }

        foreach($goods as $productId => $props) {
            if ($tmp[$props['code']]) {
                $data[$productId] = $tmp[$props['code']];
            }
        }

        $basket = Sale\Basket::loadItemsForFUser(Sale\Fuser::getId(), \Bitrix\Main\Context::getCurrent()->getSite());

        foreach($basket as $basketItem) {
            if ($data[$basketItem->getProductId()]) {
                $basketItem->setFields(array(
                    'CURRENCY' => \Bitrix\Currency\CurrencyManager::getBaseCurrency(),
                    'LID' => \Bitrix\Main\Context::getCurrent()->getSite(),
                    'PRICE' => $data[$basketItem->getProductId()]['discounted_price'],
                    'CUSTOM_PRICE' => 'Y',
                ));
            }
        }

        $basket->save();
    }

    /**
     * @return false|string
     */
    private function getPhone() {
        global $USER;

        $phone = (string)$USER->GetParam('PERSONAL_PHONE');

        echo '<pre>';
        var_dump($USER->Fetch());

        if (!$USER->IsAuthorized() || !$phone) {
            return false;
        } else {
            return $phone;
        }
    }

    public static function addClient(
        string $phone,
        string $fio,
        string $email
    )
    {

        $isUpdate  = false;
        $birthDate = Connector::DEFAULT_BIRTH_DATE;
        $isMale    = Connector::DEFAULT_SEX;

        try {
            $response = Connector::addClient(
                $isUpdate,
                $phone,
                $fio,
                $email
            );
        } catch (\Exception $e) {
            return [
                'success'     => 0,
                'error'       => 1,
                'result_text' => $e->getMessage(),
            ];
        }

        $response['success'] = 1;
        $response['error']   = 0;

        return $response;
    }

    public static function updateClient(
        string $fio,
        string $email
    )
    {
        $phone = self::getInstance()->getPhone();

        if (!$phone) {
            return [
                'success'     => 0,
                'error'       => 0,
                'result_text' => 'Нет телефона',
            ];
        }

        $isUpdate = true;

        try {
            $response = Connector::addClient(
                $isUpdate,
                $phone,
                $fio,
                $email
            );
        } catch (\Exception $e) {
            return [
                'success'     => 0,
                'error'       => 1,
                'result_text' => $e->getMessage(),
            ];
        }

        $response['success'] = 1;
        $response['error']   = 0;

        return $response;
    }

    public static function basketRecalc(
        int $bonusOut,
        array $promocodes
    )
    {
        /**
         * Если $phone === null, то будет расчет на технического пользователя.
         */
        $phone    = self::getInstance()->getPhone() ?? null;
        $billData = null;

        try {
            $_goods = $goods = self::getInstance()->formatKilbilGoodsFromBasket();

            sort($_goods);

            $response = Connector::basketRecalc(
                $phone,
                $bonusOut,
                $_goods,
                $promocodes,
            );

            $billData = $response['bill_data']['items'];

            self::getInstance()->updateBasket($goods, $billData);
        } catch (\Exception $e) {
            return [
                'success'     => 0,
                'error'       => 1,
                'result_text' => $e->getMessage(),
            ];
        }

        $response['success'] = 1;
        $response['error']   = 0;

        return $response;
    }

    public static function confirmOrder(
        int   $bonusOut,
        array $promocodes = []
    )
    {
        global $USER;

        $phone     = self::getInstance()->getPhone() ?? null;
        $isNewUser = !$USER->IsAuthorized();

        try {
            $goods = self::getInstance()->formatKilbilGoodsFromBasket();
            sort($goods);

            $response = Connector::submitOrder(
                $phone,
                $bonusOut,
                $goods,
                $promocodes,
                $orderId = '6',
                $isNewUser
            );
        } catch (\Exception $e) {
            return [
                'success'     => 0,
                'error'       => 1,
                'result_text' => $e->getMessage(),
            ];
        }

        $response['success'] = 1;
        $response['error']   = 0;

        return $response;
    }

    public static function getBonusHistory()
    {
        $phone = self::getInstance()->getPhone();

        if (!$phone) {
            return [
                'success'     => 0,
                'error'       => 0,
                'result_text' => 'Нет телефона',
            ];
        }

        try {
            return Connector::getBonusHistory($phone);
        } catch (\Exception $e) {
            return [
                'success'     => 0,
                'error'       => 1,
                'result_text' => $e->getMessage(),
            ];
        }
    }

    public static function getClientData(array $promocodes = [])
    {
        $phone = self::getInstance()->getPhone();

        if (!$phone) {
            return [
                'success'     => 0,
                'error'       => 0,
                'result_text' => 'Нет телефона',
            ];
        }

        try {
            $goods = self::getInstance()->formatKilbilGoodsFromBasket();
            sort($goods);

            $response = Connector::searchClientBy('phone', $phone, $goods, $promocodes);

            $response['success'] = 1;
            $response['error']   = 0;

            return $response;
        } catch (\Exception $e) {
            return [
                'success'     => 0,
                'error'       => 1,
                'result_text' => $e->getMessage(),
            ];
        }
    }

    public static function manualAdd(
        string $bonusIn,
        string $bonusOut,
        string $desc
    )
    {
        $phone = self::getInstance()->getPhone();

        if (!$phone) {
            return [
                'success'     => 0,
                'error'       => 0,
                'result_text' => 'Нет телефона',
            ];
        }

        try {
            $basket = Sale\Basket::loadItemsForFUser(Sale\Fuser::getId(), \Bitrix\Main\Context::getCurrent()->getSite());
            $basketTotalSum = $basket->getPrice();

            $response = Connector::manualadd(
                $phone,
                $bonusIn,
                $bonusOut,
                $basketTotalSum,
                $desc
            );

            $response['success'] = 1;
            $response['error']   = 0;

            return $response;
        } catch (\Exception $e) {
            return [
                'success'     => 0,
                'error'       => 1,
                'result_text' => $e->getMessage(),
            ];
        }
    }
}