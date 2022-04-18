<?php

namespace AkbankTransfer;

use Psr\Log\LoggerInterface;

class AkbankTransferService
{
    const CODE = '00046';
    const ID = 'akbank';
    const NAME = 'Akbank';

    const REASON_KONUT_KIRASI = '01';
    const REASON_ISYERI_KIRASI = '02';
    const REASON_DIGER_KIRALAR = '03';
    const REASON_PERSONEL_ODEMELER = '04';
    const REASON_AIDAT = '05';
    const REASON_EGITIM = '06';
    const REASON_HACIZ_ODEME = '08';
    const REASON_DIGER_ODEMELER = '99';

    const CURRENCY_CODE_TRY = '888';

    const PROCESS_TYPE_HAVALE = 'H';

    /** @var \SoapClient */
    private $client;

    private $wsdl = 'https://apigateuat.akbank.com/api/MoneyOrderService?wsdl';

    /**
     * default options
     * @var array
     */
    private $clientOptions = [
        'soap_version' => SOAP_1_1,
        'cache_wsdl' => WSDL_CACHE_DISK,
        'trace' => true,
        'exceptions' => true,
        'encoding' => 'UTF-8',
        'user_agent' => 'Thodex Bankmanager 1.0',
        'connection_timeout' => 20,
        'features' => SOAP_SINGLE_ELEMENT_ARRAYS
    ];

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var string|null */
    private $senderIban;

    /** @var string|null */
    private $senderBranch;

    /** @var string|null */
    private $senderAccount;

    /**
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        $this->configure($config);
    }

    /*
    |--------------------------------------------------------------------------
    | Setters, overrides defaults
    |--------------------------------------------------------------------------
    */
    public function fromIban(string $iban): self
    {
        $this->senderIban = $iban;
        return $this;
    }

    public function fromAccount(string $branch, string $account)
    {
        $this->senderBranch = $branch;
        $this->senderAccount = $account;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /*
    |--------------------------------------------------------------------------
    | Service Actions
    |--------------------------------------------------------------------------
    */
    /**
     * @param string $txnId
     * @param float $amount
     * @param string $receiverIban
     * @param string $receiverName
     * @param string|null $description
     * @param null|\DateTime|string $date format: d.m.Y
     *
     * @return array
     * ReturnCode int
     * EftRefNo string
     */
    public function eftToIban(
        string $txnId,
        float $amount,
        string $receiverIban,
        string $receiverName,
        string $description = null,
        string $date = null
    ): array
    {
        $receiverName = \mb_strtoupper(\str_replace(' ', '', $receiverName));
        $date = $this->makeDate($date);

        return $this->call(
            'EftTransfer',
            $this->requestEftTransfer(
                $amount,
                $date,
                $txnId,
                $this->senderIban,
                $receiverIban,
                'H',
                0,
                0,
                $receiverName,
                $description,
                self::REASON_DIGER_ODEMELER
            )
        );
    }

    /**
     * @param string $txnId
     * @param float $amount
     * @param string $bankCode
     * @param string $branchCode
     * @param string $account
     * @param string $receiverName
     * @param string|null $description
     * @param string|null $date
     *
     * @return array
     * ReturnCode int
     * EftRefNo string
     * DekontKey string|null
     */
    public function eftToAccount(
        string $txnId,
        float $amount,
        string $bankCode,
        string $branchCode,
        string $account,
        string $receiverName,
        string $description = null,
        string $date = null
    ): array
    {
        $date = $this->makeDate($date);
        $result = $this->call('EftTransfer', $this->requestEftTransfer(
            $amount,
            $date,
            $txnId,
            $this->senderIban,
            $account,
            'H',
            $bankCode,
            $branchCode,
            $receiverName,
            $description,
            self::REASON_DIGER_ODEMELER
        ));

        $this->setResultDekontKey($result);
        return $result;
    }

    /**
     * @param string $txnId
     * @param float $amount
     * @param string $receiverIban
     * @param string|null $description
     * @param string|null $date
     * @param string|null $identityNo
     *
     * @return array
     * ReturnCode
     * TransferRefNo
     * PayeeNameSurname
     */
    public function transferToIban(
        string $txnId,
        float $amount,
        string $receiverIban,
        string $description = null,
        string $date = null,
        string $identityNo = null
    ): array
    {
        $date = $this->makeDate($date);
        $request = $this->requestTransfer(
            $amount,
            0,
            0,
            $receiverIban,
            $identityNo,
            $description,
            $date,
            $txnId
        );

        $result =  $this->call('Transfer', $request);
        $this->setResultDekontKey($result);
        return $result;
    }

    /**
     * @param string $txnId
     * @param float $amount
     * @param string $branch
     * @param string $account
     * @param string|null $description
     * @param string|null $date
     * @param string|null $identityNo
     * @param string|null $iban
     *
     * @return array
     * ReturnCode
     * TransferRefNo
     * PayeeNameSurname
     */
    public function transferToAccount(
        string $txnId,
        float $amount,
        string $branch,
        string $account,
        string $description = null,
        string $date = null,
        string $identityNo = null,
        string $iban = null
    ): array {
        $date = $this->makeDate($date);
        $result = $this->call(
            'Transfer',
            $request = $this->requestTransfer(
                $amount,
                $branch,
                $account,
                $iban,
                $identityNo,
                $description,
                $date,
                $txnId
            )
        );

        $this->setResultDekontKey($result);
        return $result;
    }

    /**
     * @param string $txnId
     * @param string|null $txnDate
     * @return array
     *
     * ReturnCode
     * ErrorCode
     */
    public function getTransactionStatus(string $txnId, string $txnDate = null): array
    {
        $txnDate = $this->makeDate($txnDate);
        return $this->call(
            'GetTransactionStatus',
            $this->requestGetTransactionStatus($txnId, $txnDate)
        );
    }

    public function getReceipt(string $dekontKey, string $email): array
    {
        $request = new \stdClass();
        $request->dekontRequest = new \stdClass();
        $request->dekontRequest->authantication = $this->requestAuth();
        $request->dekontRequest->DekontKey = $dekontKey;
        $request->dekontRequest->Email = $email;
        return $this->call('GetReceipt', $request);
    }

    /**
     * @param string $txnId
     * @return array
     *
     * ReturnCode: int
     * AccessToken: string
     * TokenExpireDate: string
     */
    public function getToken(string $txnId)
    {
        $request = $this->requestGetToken($txnId);
        return $this->call('GetToken', $request);
    }


    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */
    public static function isResultSucceed(array $result): bool
    {
        return $result['ReturnCode'] === 0 && (int) $result['ReturnCode'] === 0;
    }

    public static function getResultRefCode(array $result): string
    {
        return $result['EftRefNo'] ?? $result['TransferRefNo'];
    }

    public static function getResultDekontCode(array $result): string
    {
        \preg_match('#\((.*?)\)#', $result['ReturnMessage'], $match);
        return $match[1] ?? '';
    }

    public static function isValidIban(string $iban): bool
    {
        return \strlen($iban) === 26 && \substr($iban, 0, 2) === 'TR';
    }

    public static function isAkbankIban(string $iban): bool
    {
        return \substr($iban, 7, 2) === '46';
    }

    /**
     * @param array $config
     * @throws \SoapFault|\InvalidArgumentException
     */
    private function configure(array $config)
    {
        if (empty($config['username'])) {
            throw new \InvalidArgumentException('Missing username in config.');
        }

        if (empty($config['password'])) {
            throw new \InvalidArgumentException('Missing password in config.');
        }

        $this->username = $config['username'];
        $this->password = $config['password'];

        if (! empty($config['sender_iban'])) {
            $this->senderIban = $config['sender_iban'];
        }
        if (! empty($config['sender_branch'])) {
            $this->senderBranch = $config['sender_branch'];
        }
        if (! empty($config['sender_account'])) {
            $this->senderAccount = $config['sender_account'];
        }

        if (! empty($config['wsdl'])) {
            $this->wsdl = $config['wsdl'];
        }

        if (! empty($config['client_options'])) {
            $this->clientOptions = $this->clientOptions + $config['client_options'];
        }

        $this->client = new \SoapClient($this->wsdl, $this->clientOptions);
    }

    /**
     * @param string $action
     * @param \stdClass $request
     * @return array
     */
    private function call(string $action, \stdClass $request): array
    {
        $return = [
            'ReturnCode' => -1,
            'ErrorCode' => '',
            'ReturnMessage' => null
        ];

        try {
            $response = $this->client->{$action}($request);
            $return = (array) current((array) $response);
        } catch (\Exception $e) {
            $return['ReturnCode'] = -1;
            $return['ReturnMessage'] = $e->getMessage();
        }
        finally {
            if ($this->logger !== null) {
                $this->logger->info(
                    "<log><request><header>{$this->client->__getLastRequestHeaders()}</header>"
                    . "<body>{$this->client->__getLastRequest()}</body></request>"
                    . "<response><header>{$this->client->__getLastResponseHeaders()}</header>"
                    . "<body>{$this->client->__getLastResponse()}</body></response></log>"
                );
            }
        }

        return $return;
    }

    private function makeDate(string $date = null)
    {
        if ($date === null) {
            $date = date('d.m.Y');
        } elseif ($date instanceof \DateTime) {
            $date = $date->format('d.m.Y');
        }

        return $date;
    }

    private function setResultDekontKey(array &$result)
    {
        $result['DekontKey'] = null;
        if (self::isResultSucceed($result)) {
            $result['DekontKey'] = self::getResultDekontCode($result);
        }
    }


    /*
     |--------------------------------------------------------------------------
     | Request Object Creators
     |--------------------------------------------------------------------------
     */
    private function requestGetTransactionStatus(string $txnId, string $txnDate): \stdClass
    {
        $txnInfo = new \stdClass();
        $txnInfo->authantication = $this->requestAuth();

        $txnInfo->eft = new \stdClass();
        $txnInfo->eft->FirmId = $this->username;
        $txnInfo->eft->TransactionDate = $txnDate;
        $txnInfo->eft->TransactionId = $txnId;

        $request = new \stdClass();
        $request->transactionInfoRequest = $txnInfo;

        return $request;
    }

    private function requestTransfer(
        float $amount,
        string $branch = null,
        string $account = null,
        string $iban = null,
        string $identityNo = null,
        string $description = null,
        string $date = null,
        string $txnId = null
    ): \stdClass {
        $havale = new \stdClass();
        $havale->authantication = $this->requestAuth();

        $havale->havale = new \stdClass();
        $havale->havale->Amount = $amount;

        $havale->havale->IdentityNo = $identityNo;

        $havale->havale->CheckIdentityNo = 'H';
        $havale->havale->FirmId = $this->username;
        $havale->havale->PayeeAccountNo = $account;
        $havale->havale->PayeeBranchCode = $branch;
        $havale->havale->PayeeIBAN = $iban;
        $havale->havale->ProcessType = self::PROCESS_TYPE_HAVALE;
        $havale->havale->ReasonCode = self::REASON_DIGER_ODEMELER;
        $havale->havale->RemitterAccountNo = $this->senderAccount;
        $havale->havale->RemitterBranchCode = $this->senderBranch;
        $havale->havale->RemitterCurrencyCode = self::CURRENCY_CODE_TRY;
        $havale->havale->TransactionDate = $date;
        $havale->havale->TransactionDescription = $description;
        $havale->havale->TransactionId = $txnId;

        $request = new \stdClass();
        $request->havaleRequest = $havale;

        return $request;
    }

    private function requestEftTransfer(
        $amount,
        $transactionDate = null,
        $transactionId = null,
        $remitterIban = null,
        $payeeAccountNo = null,
        $isCreditCard = 'H',
        $bankCode = 0,
        $brancCode = 0,
        $payeeNameSurname = null,
        $transactionDescription = null,
        $reasonCode = null
    ): \stdClass
    {
        $eft = new \stdClass();
        $eft->authantication = $this->requestAuth();

        $eft->eft = new \stdClass();
        $eft->eft->Amount = $amount;
        $eft->eft->BankCode = $bankCode;
        $eft->eft->BranchCode = $brancCode;
        $eft->eft->FirmId = $this->username;
        $eft->eft->IsCreditCard = $isCreditCard;
        $eft->eft->PayeeAccountNo = $payeeAccountNo;
        $eft->eft->PayeeNameSurname = $payeeNameSurname;
        $eft->eft->ReasonCode = $reasonCode;
        $eft->eft->RemitterIBAN = $remitterIban;
        $eft->eft->TransactionDescription = $transactionDescription;
        $eft->eft->TransactionId = $transactionId;
        $eft->eft->TransactionDate = $transactionDate;

        $request = new \stdClass();
        $request->eftRequest = $eft;

        return $request;
    }

    private function requestGetToken(string $txnId): \stdClass
    {
        $request = new \stdClass();
        $request->tokenRequest = new \stdClass();
        $request->tokenRequest->authantication = $this->requestAuth();
        $request->tokenRequest->TransactionId = $txnId;

        return $request;
    }

    private function requestAuth(): \stdClass
    {
        $auth = new \stdClass();
        $auth->UserName = $this->username;
        $auth->Password = $this->password;
        return $auth;
    }
}
