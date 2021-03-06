<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2016 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Crowdfunding\Reward;
use Joomla\Utilities\ArrayHelper;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');
jimport('Prism.libs.PesaPal.OAuth');

JObserverMapper::addObserverClassToClass(
    'Crowdfunding\\Observer\\Transaction\\TransactionObserver',
    'Crowdfunding\\Transaction\\TransactionManager',
    array('typeAlias' => 'com_crowdfunding.payment')
);

/**
 * Crowdfunding PesaPal payment plugin.
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentPesapal extends Crowdfunding\Payment\Plugin
{
    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);

        $this->serviceProvider = 'PesaPal';
        $this->serviceAlias    = 'pesapal';
        $this->textPrefix     .= '_' . strtoupper($this->serviceAlias);
        $this->debugType      .= '_' . strtoupper($this->serviceAlias);
        $this->errorType      .= '_' . strtoupper($this->serviceAlias);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass                 $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     *
     * @return string
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $html   = array();
        $html[] = '<div class="well">';

        $html[] = '<img src="plugins/crowdfundingpayment/pesapal/images/pesapal_icon.png" width="190" height="80" alt="' . JText::_($this->textPrefix . '_TITLE') . '" />';

        // Prepare payment receiver.
        $consumerKey    = trim($this->params->get('consumer_key'));
        $consumerSecret = trim($this->params->get('consumer_secret'));
        if (!$consumerKey or !$consumerSecret) {
            $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_NOT_CONFIGURED'));

            return implode("\n", $html);
        }

        // Get payment session
        $userStateContext = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $userState        = $this->app->getUserState($userStateContext);

        $paymentSession = $this->getPaymentSession(array(
            'session_id' => $userState->session_id
        ));

        // Prepare user data.
        $userData = $this->getUserData($paymentSession);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_USER_DATA'), $this->debugType, $userData) : null;

        if (!$userData['email']) {
            $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_REQUIRES_EMAIL'));
            $html[] = '</div>';

            return implode("\n", $html);
        }

        // Display additional information.
        $html[] = '<p>' . JText::_($this->textPrefix . '_INFO') . '</p>';

        // Generate order ID and store it to the payment session.
        $orderId = strtoupper(Prism\Utilities\StringHelper::generateRandomString(16, 'PP'));

        $amount    = number_format($item->amount, 2); //format amount to 2 decimal places
        $desc      = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', $item->title);
        $type      = 'MERCHANT'; //default value = MERCHANT
        $reference = $orderId; //unique order id of the transaction, generated by merchant
        $firstName = $userData['first_name']; //[optional]
        $lastName  = $userData['last_name']; //[optional]
        $email     = $userData['email'];

        $postXml = '<?xml version="1.0" encoding="utf-8"?><PesapalDirectOrderInfo xmlns:xsi="http://www.w3.org/2001/XMLSchemainstance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" Amount="' . $amount . '" Currency="'.$item->currencyCode.'" Description="' . $desc . '" Type="' . $type . '" Reference="' . $reference . '" FirstName="' . $firstName . '" LastName="' . $lastName . '" Email="' . $email . '" xmlns="http://www.pesapal.com" />';

        // Store data in payment session.
        $paymentSession->setOrderId($orderId);
        $paymentSession->setData('pesapal.amount', $amount);
        $paymentSession->setData('pesapal.currency_code', $item->currencyCode);
        $paymentSession->store();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_POST_XML'), $this->debugType, $postXml) : null;

        $postXml = htmlentities($postXml);

        $signatureMethod = new OAuthSignatureMethod_HMAC_SHA1();
        $consumer        = new OAuthConsumer($this->params->get('consumer_key'), $this->params->get('consumer_secret'));
        $token           = null;
        $pesaPalParams   = null;

        $iframeUrl       = $this->params->get('test_enabled', 1) ? $this->params->get('test_merchant_url') : $this->params->get('merchant_url');
        $callbackUrl     = $this->getReturnUrl($item->slug, $item->catslug) . '&pid='. (int)$item->id;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_IFRAME_URL'), $this->debugType, $iframeUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CALLBACK_URL'), $this->debugType, $callbackUrl) : null;

        //post transaction to pesapal
        $iframeSrc = OAuthRequest::from_consumer_and_token($consumer, $token, 'GET', $iframeUrl, $pesaPalParams);
        $iframeSrc->set_parameter('oauth_callback', $callbackUrl);
        $iframeSrc->set_parameter('pesapal_request_data', $postXml);
        $iframeSrc->sign_request($signatureMethod, $consumer, $token);

        // Start the form.
        $html[] = '<iframe src="' . $iframeSrc . '" width="100%" height="720px" scrolling="auto" frameBorder="0"> <p>' . JText::_('PLG_CROWDFUNDINGPAYMENT_PESAPAL_ERROR_UNABLE_TO_LOAD') . '</p> </iframe>';

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * Prepare user data.
     *
     * @param Crowdfunding\Payment\Session $paymentSession
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    protected function getUserData($paymentSession)
    {
        $result = array(
            'first_name' => '',
            'last_name'  => '',
            'email'      => ''
        );

        $user = JFactory::getUser();
        if (!$user->get('id')) {
            // Get name and email from anonymous data records.
            if ($paymentSession->isAnonymous() and JComponentHelper::isEnabled('com_crowdfundingdata')) {
                $user = new Crowdfundingdata\Record(JFactory::getDbo());
                $user->load(array('session_id' => $paymentSession->getAnonymousUserId()));
                if ($user->getId()) {
                    $userNames            = explode(' ', $user->getName());
                    $result['first_name'] = ArrayHelper::getValue($userNames, 0, '', 'string');
                    $result['last_name']  = ArrayHelper::getValue($userNames, 1, '', 'string');

                    $result['email'] = $user->getEmail();
                }
            }

        } else {
            $userNames = explode(' ', $user->get('name'));

            $result['first_name'] = ArrayHelper::getValue($userNames, 0, '', 'string');
            $result['last_name']  = ArrayHelper::getValue($userNames, 1, '', 'string');
            $result['email']      = $user->get('email');
        }

        return $result;
    }

    /**
     * This method processes transaction data that comes from PayPal instant notifier.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return null|stdClass
     */
    public function onPaymentNotify($context, $params)
    {
        if (strcmp('com_crowdfunding.notify.' . $this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('GET', $requestMethod) !== 0) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'),
                $this->debugType,
                JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod)
            );

            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_GET_RESPONSE'), $this->debugType, $_GET) : null;

        // Prepare the array that have to be returned by this method.
        $paymentResult                  = new stdClass;
        $paymentResult->project         = null;
        $paymentResult->reward          = null;
        $paymentResult->transaction     = null;
        $paymentResult->paymentSession  = null;
        $paymentResult->serviceProvider = $this->serviceProvider;
        $paymentResult->serviceAlias    = $this->serviceAlias;

        // Parameters sent to you by PesaPal IPN
        $pesapalNotification = $this->app->input->get->get('pesapal_notification_type');
        $pesapalTrackingId   = $this->app->input->get->get('pesapal_transaction_tracking_id', null, 'raw');
        $pesapalOrderId      = $this->app->input->get->get('pesapal_merchant_reference', null, 'string');

        // Get the status data from PesaPal server.
        $data = null;
        if (strcmp($pesapalNotification, 'CHANGE') === 0 and $pesapalTrackingId !== '') {
            $data = $this->getStatusData($pesapalOrderId, $pesapalTrackingId);
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DATA'), $this->debugType, $data) : null;

        if ($data !== null) {
            // Get payment session data
            $paymentSessionRemote = $this->getPaymentSession(array(
                'unique_key' => $pesapalTrackingId,
                'order_id'   => $pesapalOrderId
            ));

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

            if ($pesapalOrderId !== $paymentSessionRemote->getOrderId()) {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_ORDER_ID'), $this->debugType, $pesapalOrderId);
                return null;
            }

            $containerHelper  = new Crowdfunding\Container\Helper();
            $currency         = $containerHelper->fetchCurrency($this->container, $params);

            // Prepare valid transaction data.
            $options = array(
                'currency_code' => $currency->getCode(),
                'timezone'      => $this->app->get('offset'),
            );

            $validData = $this->validateData($data, $paymentSessionRemote, $options);
            if ($validData === null) {
                return null;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

            // Get Project and set the receiver ID.
            $project = $containerHelper->fetchProject($this->container, $validData['project_id']);
            $validData['receiver_id'] = $project->getUserId();
            
            // Get reward object.
            $reward = null;
            if ($validData['reward_id']) {
                $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
            }

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transaction = $this->storeTransaction($validData);
            if ($transaction === null) {
                return null;
            }
            
            // Generate object of data, based on the transaction properties.
            $paymentResult->transaction = $transaction;

            // Generate object of data based on the project properties.
            $paymentResult->project = $project;

            // Generate object of data based on the reward properties.
            if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                $paymentResult->reward = $reward;
            }

            // Generate data object, based on the payment session properties.
            $paymentResult->paymentSession = $paymentSessionRemote;

            // Removing intention.
            $this->removeIntention($paymentSessionRemote, $transaction);
        }

        return $paymentResult;
    }

    protected function getStatusData($orderId, $trackingId)
    {
        // Prepare payment receiver.
        $consumerKey    = trim($this->params->get('consumer_key'));
        $consumerSecret = trim($this->params->get('consumer_secret'));
        if (!$consumerKey or !$consumerSecret) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_CREDENTIALS'), $this->errorType);
            return null;
        }

        $apiUrl           = $this->params->get('test_enabled', 1) ? 'https://demo.pesapal.com/api' : 'https://www.pesapal.com/api';

        $token            = null;
        $consumer         = new OAuthConsumer($consumerKey, $consumerSecret);
        $signature_method = new OAuthSignatureMethod_HMAC_SHA1();

        //get transaction status
        $requestStatus = OAuthRequest::from_consumer_and_token($consumer, $token, 'GET', $apiUrl . '/QueryPaymentStatus', null);
        $requestStatus->set_parameter('pesapal_merchant_reference', $orderId);
        $requestStatus->set_parameter('pesapal_transaction_tracking_id', $trackingId);
        $requestStatus->sign_request($signature_method, $consumer, $token);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestStatus);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        if (defined('CURL_PROXY_REQUIRED') and CURL_PROXY_REQUIRED === 'True') {
            $proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') and strtoupper(CURL_PROXY_TUNNEL_FLAG) === 'FALSE') ? false : true;
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);

            if (defined('CURL_PROXY_SERVER_DETAILS')) {
                curl_setopt($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
            }
        }

        $response = curl_exec($ch);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $response) : null;

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
//        $raw_header  = substr($response, 0, $header_size - 4);
//        $headerArray = explode("\r\n\r\n", $raw_header);
//        $header      = $headerArray[count($headerArray) - 1];

        // Transaction status
        $elements = preg_split('/=/', substr($response, $header_size));

        curl_close($ch);

        return array('status' => $elements[1]);
    }

    /**
     * Complete checkout.
     *
     * @param string                   $context
     * @param stdClass                 $item
     * @param Joomla\Registry\Registry $params
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     *
     * @return stdClass|null
     */
    public function onPaymentsCompleteCheckout($context, $item, $params)
    {
        JDEBUG ? $this->log->add('context', $this->debugType, $context) : null;

        if (strcmp('com_crowdfunding.payments.completecheckout.' . $this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CALLBACK_RESPONSE'), $this->debugType, $_GET) : null;

        // Get payment session
        $userStateContext = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $userState        = $this->app->getUserState($userStateContext);

        $paymentSessionRemote = $this->getPaymentSession(array(
            'session_id' => $userState->session_id
        ));

        $trackingId = $this->app->input->get->get('pesapal_transaction_tracking_id', null, 'raw');
        $orderId    = $this->app->input->get->get('pesapal_merchant_reference', null, 'string');

        // Store tracking ID.
        if (($orderId !== null and $trackingId !== null) and ($orderId === $paymentSessionRemote->getOrderId())) {
            $paymentSessionRemote->setUniqueKey($trackingId);
            $paymentSessionRemote->storeUniqueKey();
        }

        $paymentResult = new stdClass;
        $paymentResult->redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug, 'share'));

        return $paymentResult;
    }

    /**
     * Validate PayPal transaction.
     *
     * @param array                        $data
     * @param Crowdfunding\Payment\Session $paymentSession
     * @param array                        $options
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     * @return array
     */
    protected function validateData($data, $paymentSession, $options)
    {
        $date      = new JDate('now', $options['timezone']);

        $txnStatus = strtolower(ArrayHelper::getValue($data, 'status', '', 'string'));
        $txnStatus = ($txnStatus === 'invalid') ? 'failed' : $txnStatus;

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => (int)$paymentSession->getUserId(),
            'project_id'       => (int)$paymentSession->getProjectId(),
            'reward_id'        => $paymentSession->isAnonymous() ? 0 : (int)$paymentSession->getRewardId(),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
            'txn_id'           => $paymentSession->getOrderId(),
            'txn_amount'       => $paymentSession->getData('pesapal.amount'),
            'txn_currency'     => $paymentSession->getData('pesapal.currency_code'),
            'txn_status'       => $txnStatus,
            'txn_date'         => $date->toSql()
        );

        // Check Project ID and Transaction ID
        if (!$transaction['project_id'] or !$transaction['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, $transaction);
            return null;
        }

        // Check if project record exists in database.
        $projectRecord = new Crowdfunding\Validator\Project\Record(JFactory::getDbo(), $transaction['project_id']);
        if (!$projectRecord->isValid()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'), $this->debugType, $transaction);
            return null;
        }

        // Check if reward record exists in database.
        if ($transaction['reward_id'] > 0) {
            $rewardRecord = new Crowdfunding\Validator\Reward\Record(JFactory::getDbo(), $transaction['reward_id'], array('state' => Prism\Constants::PUBLISHED));
            if (!$rewardRecord->isValid()) {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_REWARD'), $this->debugType, $transaction);
                return null;
            }
        }

        // Check currency
        if (strcmp($transaction['txn_currency'], $options['currency_code']) !== 0) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'),
                $this->debugType,
                array('TRANSACTION DATA' => $transaction, 'CURRENCY' => $options['currency_code'])
            );
            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction data.
     *
     * @param array $transactionData
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     *
     * @return Transaction|null
     */
    protected function storeTransaction($transactionData)
    {
        // Get transaction by txn ID
        $keys        = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        // If the current status if completed, stop the payment process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Add extra data.
        if (array_key_exists('extra_data', $transactionData)) {
            if (!empty($transactionData['extra_data'])) {
                $transaction->addExtraData($transactionData['extra_data']);
            }

            unset($transactionData['extra_data']);
        }

        // IMPORTANT: It must be before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData['txn_status']
        );

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);
        
        // Start database transaction.
        $db = JFactory::getDbo();
        $db->transactionStart();

        try {
            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        // Commit database transaction.
        $db->transactionCommit();

        return $transaction;
    }
}
