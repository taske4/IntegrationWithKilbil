<?php


namespace Kilbil;

use ErrorException;
use Helpers\Trash;
use GuzzleHttp\Psr7\Request;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Connector
{
    public const DEFAULT_BIRTH_DATE = '01.01.1999';

    public const DEFAULT_SEX = true; // Male

    /*
     * Custom application(Внешнее приложение)), без этого параметра вернутся все действующие карты клиента
     */
    private const OUTER_SYSTEMS_INTERFACE_TYPE = 5;

    private const SEARCH_MODE = [
        'phone'      => 0,
        'cardNumber' => 2,
        'clientId'   => 3,
    ];

    /*
     * Collection required props &
     * types
     */
    private const PRODUCT_PROPS = [
        'code'     => 'string',
        'price'    => 'float|int',
        'minPrice' => 'float|int',
        'quantity' => 'int',
        'total'    => 'float|int',
//            'discounted_price' => 'float',
//            'discounted_total' => 'float,'
    ];

    private const SERVICE_USER_PHONE = '79343434343';

    private const SERVICE_USER_CLIENT_ID = '15374328';

    private const SERVICE_USER_CARD_NUM = '2828280393715';

    private $REQUEST_URL_TEMPLATE;

    private $APIKEY;

    private $REQUEST_CLIENT;

    private const MSG_BY_STATUS_CODE = [
        403 => 'Не зарегистрирован ApiKey.',
        404 => 'Проверьте адрес обращения а Api',
        500 => 'Функция не найдена, проверьте название функции.',
    ];

    private $log;

    private static $instance;

    private function __construct()
    {
        $this->log = new Logger('KILBIL');
        $this->log->pushHandler(new StreamHandler($_SERVER['DOCUMENT_ROOT'].'/local/logs/kilbil.log', Logger::DEBUG));
        $this->log->alert('--------');
        $this->log->alert('--------');
        $this->log->alert('-------- Init');

        if (!($this->APIKEY = Trash::get('KILBIL_APIKEY'))) {
            throw new \ErrorException('APIKEY not found', 0);
        }

        $this->REQUEST_URL_TEMPLATE = 'https://bonus.kilbil.ru/load/%FUNCTION_NAME%?h=' . $this->APIKEY;
        $this->REQUEST_CLIENT       = new \GuzzleHttp\Client();
    }

    private static function getInstance()
    {
        return self::$instance ? self::$instance : self::$instance = new self();
    }

    private function request($kilbilFunctionName, $reqParams)
    {
        $this->log->debug('-------- Kilbil Function name: '.$kilbilFunctionName);

        $reqParams = json_encode($reqParams);

        $this->log->debug('Request params: (json)'.$reqParams);

        $url = str_replace('%FUNCTION_NAME%', $kilbilFunctionName, $this->REQUEST_URL_TEMPLATE);

        $this->log->debug('Request url: '.$url);

        try {
            $req = new Request(
                'POST',
                $url,
                [
                    'Content-Type' => 'application/json',
                    'verify'       => true,
                ],
                $reqParams,
            );
//
            $res = self::getInstance()->REQUEST_CLIENT->send($req);

        } catch (\Exception $e) {
            $exceptionMsg = self::MSG_BY_STATUS_CODE[$e->getResponse()->getStatusCode()];

            $this->log->debug('Caught any Exception while request & throw ErrorException (fatal) - look at message: '. $exceptionMsg);

            throw new \ErrorException($exceptionMsg);
        }

        $body    = $res->getBody();
        $content = $body->getContents();
        $data    = json_decode($content, true);

        $this->log->debug('Response on request (json): '.$content);

        if ((!is_array($data)) || ($data['error'] !== null)) {
            $this->log->debug('throw ErrorException (fatal)');
            throw new \ErrorException('Метод:' . $kilbilFunctionName . ' ' . ($data['error_text'] ?? 'Ошибка ответа.'));
        }

        $this->log->debug('-------- request is success');

        return $data;
    }

    /**
     * @param array $goods
     * @return bool
     */
    private function validateGoods(array $goods)
    {
        $propNames = array_keys(self::PRODUCT_PROPS);

        foreach($goods as $product) {
            $productProps = array_keys($product);

            foreach($propNames as $name) {
                if ($value = $productProps[$name]) {
                    $valueType = gettype($value);
                    $needType = explode('|', self::PRODUCT_PROPS[$name]);

                    if (!in_array($valueType, $needType)) {
                        return false;
                    }
                } else {
                    return false;
                }
            }

        }

        return true;
    }

    /**
     * @param string $phone
     * @return string
     * @throws \Exception
     */
    private function normalizePhone(string $phone): string
    {
        if (preg_match('/7\d{10}/', $phone) === 1) {
            return $phone;
        }

        $phone = trim($phone);
        $phone = preg_replace('/[\s+\D]/', '', $phone);
        if (strlen($phone) === 10) {
            $phone = '7'.$phone;
        } elseif ((strlen($phone) === 11) && ($phone[0] === '8')) {
            $phone[0] = '7';
        }

        if (preg_match('/7\d{10}/', $phone) !== 1) {
            throw new \Exception('Phone isn`t valid');
        }

        return $phone;
    }

    private function formatPromocodes(array $promocodes)
    {
        $result = [
            'coupons' => [],
        ];

        foreach($promocodes as $promocode)
        {
            $result['coupons'][] = [
                'coupon' => $promocode,
            ];
        }

        return $result;
    }

    private function generateMoveId($clientId)
    {
        $moveId      = '';
        $nowDateTime = new \DateTime();
        $nowDateTime = $nowDateTime->format('dmy');
        $moveId      = $clientId.'-'.$nowDateTime.'-'.uniqid();
        $moveId      = strtoupper($moveId);
        return md5($moveId);
    }

    private function applyCoupons(
        string $phone,
        array  $coupons
    )
    {
        /*
         * ...
         */
    }

    public static function basketRecalc(
        $phone = null,
        int $bonusOut,
        array  $goods,
        array  $promocodes = []
    )
    {
        if ($phone === null) {
            $phone = self::SERVICE_USER_PHONE;
        } else {
            $phone = self::getInstance()->normalizePhone($phone);
        }

        $bonusOut  = (string)$bonusOut;

        $pomocodes = self::getInstance()->formatPromocodes($promocodes);

        /*
         * discount - скидка по чеку
         * discount_total - сумма чека со скидкой
         * max_bonus_out - Максимальная сумма списания с учетом баланса клиента
         */
        $clientData = self::getInstance()->searchClientBy(
            'phone',
            $phone,
            $goods,
            $promocodes,
        );

        $clientData['MAX_BONUS_OUT'] = $maxBonusOut = $clientData['max_bonus_out'] ?? 0;

//        self::getInstance()->generateMoveId($clientId);

        if ($bonusOut > (int)$clientData['max_bonus_out']) {
            $clientData['bonus_out'] = $clientData['max_bonus_out'];

            throw new \Exception('Желаемая сумма списания привышает допустимую сумму списания.');
        }

        $moveId = self::getInstance()->generateMoveId($clientData['client_id']);

        $calculationData = self::getInstance()->processsale(
            (string) $clientData['client_id'],
            (string) $clientData['bonus_out'],
            (string) $clientData['max_bonus_out'],
            (string) $moveId,
            (array)  $goods,
            (array)  $promocodes,
            (string) json_encode($clientData['_bill_data'])
        );

        return array_merge($clientData, $calculationData);
    }

    public static function submitOrder(
        $phone = null,
        int $bonusOut,
        array $goods,
        array $promocodes = [],
        string $orderId,
        bool $isNewUser = false//,
//        string $fio = '',
//        string $email = ''
    )
    {
        $newPhone = null;

        if ($isNewUser) {
//            $newPhone = self::getInstance()->normalizePhone($phone);
            $phone    = self::SERVICE_USER_PHONE;
        } else {
            $phone = self::getInstance()->normalizePhone($phone);
        }

        $bonusOut  = (string)$bonusOut;

//        $pomocodes = self::getInstance()->formatPromocodes($promocodes);

        $basketInfo = self::basketRecalc(
            $phone,
            $bonusOut,
            $goods,
            $promocodes
        );

//        if ($newPhone && $isNewUser) {
//            $res = self::addClient(
//                $newPhone,
//                $fio,
//                '',
//                '23.12.2021',
//                true,
//            );
//        }

//        self::getInstance()->applyCoupons($basketInfo, $promocodes);

        if ((int)$basketInfo['bonus_out'] > 0) {
            self::manualadd(
                (string)$basketInfo['client_id'],
                (string)$bonusIn = 0,
                (string)$basketInfo['bonus_out'],
                (string)$basketInfo['discounted_total'],
                'Списание по заказу, №' . ($orderId ?? 'null'),
            );
        }
    }

    /**
     * @param string $phone
     * @param string $fio
     * @param int $clientId
     * @param string $email
     * @param string $birthDate
     * @param bool $isMale
     * @param string $cardNumber
     * @return array
     * @throws \Exception
     *
     * @desc если передан clientId обновляем данные пользователя, иначе создаем его.
     */
    public static function addClient(
        bool   $isUpdate,
        string $phone,
        string $fio,
        string $email      = '',
        string $birthDate  = self::DEFAULT_BIRTH_DATE,
        bool   $isMale     = self::DEFAULT_SEX
    )
    {
        $isNewUser = false;
        $cardNum   = null;

        $phone = self::getInstance()->normalizePhone($phone);

        $fio = preg_replace('/\s+/', ' ', trim($fio));
        $fio = explode(' ', $fio);

        $reqParams = [
            "phone"       => $phone,
            "last_name"   => $fio[0]      ?? null,
            "first_name"  => $fio[1]      ?? null,
            "middle_name" => $fio[2]      ?? null,
            "birth_date"  => $birthDate   ?? null,
            "sex"         => (int)$isMale ?? null,
            "email"       => $email       ?? null,
        ];

        $clientData = self::searchClientBy('phone', $phone);

        if ($clientData['client_id'] > 0) {
            /*
             * Вроде как пока не нужно обновлять данные карты - т.к телефон менятся не будет!
             * Номер карты похоже - что тоже нет!
             * Но я оставлю это здесь.
             */
//            self::getInstance()->onBeforeClientUpdate($clientId, [
//                'phone'      => $phone,
//                'cardNumber' => $clientData['card_barcode'],
//            ]);

            /*
             * Нужно для того, что если не обновляем данные
             * - а пытаемся добавить клиенита, случайо не обновить.
             * - скидываем поля.
             */
            if (!$isUpdate) {
//                $birthDate = $fio = $email = '';
//                return $clientData;
                throw new \Exception('Клиент уже существует');
            }
        } else {
            $isNewUser = true;

            /*
             * Тут надо создать карту, если надо.
             */

            $reqParams['outer_systems_interface_type'] = self::OUTER_SYSTEMS_INTERFACE_TYPE;
            $reqParams['card_barcode'] = $cardNum = self::getInstance()->generateCardNum();
        }

        $data = self::getInstance()->request('addclient', $reqParams);

        unset ($reqParams);

        if (((int)$data['success'] !== 1) || ((int)$data['error'] === 1)) {
            $msg = ($data['result_text'] ?? '') . (($data['result_text'] && $data['error_text']) ? ' | ' : '') . ($data['error_text'] ?? '');
            throw new \Exception($msg);
        }

        if ((int)$data['result_code'] === 0 && $isNewUser && $cardNum && $phone)
        {
            self::getInstance()->addCardInfo($cardNum, $phone);
        }

        return [
//            'clientId'    => $data['client_id'] ?? null,
            'result_text' => $data['result_text'],
        ];
    }

    private function addCardInfo(string $cardNum, string $phone)
    {
        \MA\Custom\BonusCards::add($cardNum, $phone, $opts=null);
    }

    private function generateCardNum()
    {
        return \MA\Custom\BonusCards::getNext($isDev=false);
    }

    private function onBeforeClientUpdate(int $clientId, array $data)
    {
        /**
         * Если мы обновляем данные клиента,
         * то и данные карты тоже.
         *
         * !!! не знаю очевидно или нет,
         * но $clientDada это текущие данные
         * а $data новые !!!
         */
        $clientData = self::searchClientBy('clientId', $clientId);

        $this->updateCardInfo($clientData['card_barcode'], $data['cardNumber'], $data['phone']);

        unset($currentClientData);
    }

    private function updateCardInfo(string $oldCardNumber, string $newCardNumber, string $newPhone)
    {
        $cardInfo = \MA\Custom\BonusCards::get($oldCardNumber, null);
        $hlRowId  = $cardInfo['ID'];

        $cardInfo = \MA\Custom\BonusCards::update($hlRowId, [
            'cardNum' => $newCardNumber ?? $oldCardNumber ?? null,
            'phone'   => $newPhone      ?? null,
        ]);
    }

    public static function searchpromocodes(array $promocodes)
    {
        $result = [];

        foreach ($promocodes as $promocode) {
            $data = self::getInstance()->request('searchpromocode', [
                'promo_code' => $promocode
            ]);

            $groupName = ((int)$data['result_code'] === 0) ? 'found' : 'other';
            $result[$groupName][$promocode] = [
                'text'   => $data['result_text'],
            ];
        }

        return $result;
    }

    public static function manualadd(
//        string $phone,
        string $clientId,
        string $bonusIn,
        string $bonusOut,
        string $basketTotalSum,
        string $desc
    )
    {
//        $phone = self::getInstance()->normalizePhone($phone);
//
//        $clientData = self::searchClientBy('phone', $phone);
//
        $data = self::getInstance()->request('manualadd', [
//            'client_id'   => $clientData['client_id'],
            'client_id'   => $clientId,
            'bonus_in'    => $bonusIn,  // Сумма начисления
            'bonus_out'   => $bonusOut, // Сколько хотим списать.
            'description' => $desc ?? 'Неизвестная операция.',
            'total'       => $basketTotalSum,
        ]);

        if( (int)$data['result_code'] !== 0 ) {
            throw new \Exception($data['result_text'] ?? 'Error line is '.__LINE__);
        }

        return true;
    }

    private static function processsale(
        string $clientId, // required
        string $bonusOut = '0',
        string $maxBonusOut = '0',
        string $moveId,
        array  $goods,    // required
        array  $promocodes = [],
        string $billData
    )
    {
        $data = self::getInstance()->request('processsale', [
            'client_id'     => $clientId,
            'type'          => '0', // тип операции (0 - продажа, 1 - возврат)
            'bonus_out'     => $bonusOut,
            'max_bonus_out' => $maxBonusOut,
            'move_id'       => $moveId,
//            "doc_open_dt"   => "22.12.21 17:47:35",
            'goods_data'    => $goods,
            'mode'          => 0, // режим расчета чека (0 - обычный режим, 1 - расчет только сумм скидок и списываемых бонусов, 2 - расчет только сумм начисленных бонусов)
            'bill_data_in'  => $billData,
            'promo_codes'   => $promocodes,
        ]);

        if( (int)$data['result_code'] !== 0 ) {
            throw new \Exception($data['result_text'] ?? 'Error line is '.__LINE__);
        }

        return [
            'bill_data'   => $data['_bill_data'],
            'result_text' => $data['result_text'],
//            '_bill_data' => $data['_bill_data'] ?? [],
//            ''    => $data['bonus_balance'] ?? null,
        ];
    }

    /**
     * @param string $value
     * @param array $goods
     * @return array|mixed
     * @throws \Exception
     *
     * @desc режим поиска
     * (0 – поиск по № телефона,
     * 1 – поиск по внутреннему № карты,
     * 2 – поиск по № карты,
     * 3 - поиск по id клиента)
     */
    public static function searchClientBy(
        string $searchMode,
        string $searchValue,
        array  $goods      = [],
        array  $promocodes = []
    )
    {

        if (self::SEARCH_MODE[$searchMode] === null) {
            throw new \Exception('the search mode isnt found.');
        }

        if ($searchMode === 'phone') {
            $searchValue = self::getInstance()->normalizePhone($searchValue);
        }

        $reqParams = [
            "search_mode"  => 0,
            "search_value" => $searchValue,
            "goods_data"   => $goods,
            "promo_codes"  => $promocodes,
        ];

        $data = self::getInstance()->request('searchclient', $reqParams);

//        if( (int)$data['state'] !== 1 ) {
//            // состояние клиента (NULL - не найден, 0 – пустой, 1 – активирован, 2 – заблокирован, 3 - зарегистрирован, 4 - замена карты (донор))
//            throw new \Exception('Клиент имеет статус отличающийся от Активного.');
//        }

        return $data;

        return [
            'state'              => $data['state'],
            'clientId'           => $data['client_id'] ?? null,
            'phone'              => $data['phone'] ?? null,
            'balance'            => $data['bonus_balance'] ?? null,
            'discounted_total'   => $data['discounted_total'] ?? 0,   //Сумма чека со скидкой
            'max_bill_bonus_out' => $data['max_bill_bonus_out'] ?? 0, // Максимальная сумма списания на текущий чек без учета баланса клиента
            'max_bonus_out'      => $data['max_bonus_out'] ?? 0,      // Максимальная сумма списания с учетом баланса клиента
            '_bill_data'         => $data['_bill_data'] ?? null,      // Используется для предварительного расчёта акций с ценой, возвращает id применённых акций, которые в дальнейшем нужно отправить в processsale
            'result_text'        => $data['result_text'] ?? '',
        ];
    }

    private function isClientNormal($phone)
    {
        $phone = self::normalizePhone($phone);

        $clientData = self::searchClientBy('phone', $phone);

        if (((int)$clientData['state'] === 1) && (strlen((string)$clientData['client_id']) > 0))
        {
            return true;
        } else {
            return false;
        }
    }

    public static function getBonusHistory(string $phone)
    {
        $phone = self::normalizePhone($phone);

        $clientData = self::searchClientBy('phone', $phone);

        if (!self::getInstance()->isClientNormal($phone) || !$clientData['client_id']) {
            return [
                'result_text' => 'Что то не то с клиентом.',
            ];
        }

        $reqParams = ['client_id' => $clientData['client_id']];
        $data      = self::getInstance()->request('getmovesbyclient', $reqParams);
        $moves     = $data['client_moves'];

        if ((is_array($moves)) && (count($moves) > 0)) {
            foreach($moves as &$move) {
                $move = [
                    'дата операции'     => $move['move_date'] ?? '',
                    'Активно с'         => $move['bonus_in_date'], // дата и время активации бонусов (может отличаться от даты чека из-за отложенных бонусов)
                    'Активно по'        => $move['bonus_term_date'], //  	дата и время сгорания бонусов
                    'сумма операции'    => $move['move_asum'] ?? '0',
                    'описание операции' => $move['oper_description'] ?? '',

//                    'начисленные бонусы по операции' => $move['bonus_in'] ?? '0',
//                    'списанные бонусы по операции' => $move['bonus_out'] ?? '0',
                ];
            }
        }

        return $moves;
    }
}
