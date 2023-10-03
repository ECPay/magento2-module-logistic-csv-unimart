<?php
namespace Ecpay\LogisticCsvUnimart\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Ecpay\General\Helper\Services\Config\LogisticService;
use Ecpay\General\Helper\Services\Config\MainService;

class Shipping extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'ecpaylogisticcsvunimart';

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    protected $_rateMethodFactory;

    protected $_loggerInterface;
    protected $_logisticService;
    protected $_mainService;

    /**
     * Shipping constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface          $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory  $rateErrorFactory
     * @param \Psr\Log\LoggerInterface                                    $loggerInterface
     * @param \Magento\Shipping\Model\Rate\ResultFactory                  $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param array                                                       $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $loggerInterface,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        LogisticService $logisticService,
        MainService $mainService,
        array $data = []
    ) {

        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_loggerInterface = $loggerInterface;
        $this->_logisticService = $logisticService;
        $this->_mainService = $mainService;

        parent::__construct($scopeConfig, $rateErrorFactory, $loggerInterface, $data);
    }

    /**
     * get allowed methods
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * @return float
     */
    private function getShippingPrice()
    {
        $configPrice = $this->getConfigData('price');

        $shippingPrice = $this->getFinalPriceWithHandlingFee($configPrice);

        return $shippingPrice;
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     * 控制物流是否要顯示在列表
     * 訂單金額等相關門檻在此判斷
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        // 判斷綠界物流是否啟用
        $ecpayEnableLogistic = $this->_mainService->getMainConfig('ecpay_enabled_logistic') ;
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates ecpayEnableLogistic:'. print_r($ecpayEnableLogistic,true));
        if ($ecpayEnableLogistic != 1) {
            return false ;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));

        // 物流費
        $amount = $this->getShippingPrice();
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates amount:'. print_r($amount,true));

        // 購物車金額
        $total = $request->getBaseSubtotalInclTax();
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates total:'. print_r($total,true));

        // 訂單最小金額
        $minOrderAmount = $this->getConfigData('min_order_amount') ;
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates min_order_amount:'. print_r($minOrderAmount,true));

        // 訂單最大金額
        $maxOrderAmount = $this->getConfigData('max_order_amount') ;
        $maxOrderAmount = $this->_logisticService->getCvsAvailableMaxAmount($maxOrderAmount) ;
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates max_order_amount:'. print_r($maxOrderAmount,true));

        // 免運門檻開關
        $freeShippingEnable = $this->getConfigData('free_shipping_enable') ;
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates free_shipping_enable:'. print_r($freeShippingEnable,true));

        // 免運門檻金額
        $freeShippingSubtotal = $this->getConfigData('free_shipping_subtotal') ;
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates free_shipping_subtotal:'. print_r($freeShippingSubtotal,true));

        // 購物車重量(中華郵政用)
        $shippingWeight = $request->getPackageWeight();
        $this->_loggerInterface->debug('LogisticCsvUnimart collectRates shippingWeight:'. print_r($shippingWeight,true));

        // 判斷訂單最高金額
        if ($total > $maxOrderAmount) {

            $this->_loggerInterface->debug('LogisticCsvUnimart collectRates 超過購物車最高金額門檻');
            return false;
        }

        // 判斷訂單最低金額
        if ($minOrderAmount != 0 && $total < $minOrderAmount) {

            $this->_loggerInterface->debug('LogisticCsvUnimart collectRates 低於購物車最低金額門檻');
            return false;
        }

        // 判斷免運門檻
        if ($freeShippingEnable == 1 && $total >= $freeShippingSubtotal) {

            $this->_loggerInterface->debug('LogisticCsvUnimart collectRates 到達免運門檻');
            $amount = 0 ;
        }

        $method->setPrice($amount);
        $method->setCost($amount);

        $result->append($method);

        return $result;
    }
}