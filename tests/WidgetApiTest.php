<?php
/**
 * Widget API Tests
 *
 * PHP version 7.4
 *
 * @category Test
 * @package  Dana\Tests
 * @author   DANA Indonesia
 * @link     https://dashboard.dana.id/
 */

namespace Dana\Tests;

use PHPUnit\Framework\TestCase;
use Dana\Tests\Fixtures\ApiClientFixtures;
use Dana\Tests\Fixtures\WidgetFixtures;
use Dana\Widget\v1\Api\WidgetApi;
use Dana\Widget\v1\Model\ApplyTokenRequest;
use Dana\Widget\v1\Model\ApplyTokenResponse;
use Dana\Widget\v1\Model\ApplyOTTRequest;
use Dana\Widget\v1\Model\ApplyOTTResponse;
use Dana\Widget\v1\Model\UserResource;
use Dana\Widget\v1\Model\Oauth2UrlData;
use Dana\Tests\Scripts\WebAutomation;
use Dana\Widget\v1\Model\ApplyOTTRequestAdditionalInfo;
use Dana\Widget\v1\Util\Util;

/**
 * WidgetApiTest Class
 * 
 * Tests for WidgetApi operations
 */
class WidgetApiTest extends TestCase
{
    /**
     * @var WidgetApi
     */
    private $apiInstance;
    
    /**
     * @var string
     */
    private $bindingAccessToken;
    
    /**
     * @var string
     */
    private $ott;
    
    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->apiInstance = ApiClientFixtures::getWidgetApiInstance();
    }

    /**
     * Test Payment and Query
     *
     * @return void
     */
    public function testCreatePaymentAndQuery(): void
    {
        try {
            $widgetPaymentRequest = WidgetFixtures::getWidgetPaymentRequest();
            $widgetPaymentResponse = $this->apiInstance->widgetPayment($widgetPaymentRequest);
            $this->assertNotNull($widgetPaymentResponse);
            
            $this->assertEquals('2005400', $widgetPaymentResponse->getResponseCode());
            $this->assertEquals('Successful', $widgetPaymentResponse->getResponseMessage());
            $this->assertEquals($widgetPaymentRequest->getPartnerReferenceNo(), $widgetPaymentResponse->getPartnerReferenceNo());
            $this->assertNotEmpty($widgetPaymentResponse->getWebRedirectUrl());
        } catch (\Exception $e) {
            $this->fail('Error during WidgetPayment execution: ' . $e->getMessage());
        }

        try {
            // Step 2: Query Payment
            echo "Step 2: Querying payment status..." . PHP_EOL;
            $queryPaymentRequest = WidgetFixtures::getWidgetQueryPaymentRequest(
                $widgetPaymentRequest,
                $widgetPaymentResponse
            );
            
            $queryPaymentResponse = $this->apiInstance->queryPayment($queryPaymentRequest);
            
            $this->assertNotNull($queryPaymentResponse);
            $this->assertEquals('2005500', $queryPaymentResponse->getResponseCode());
            $this->assertEquals('Successful', $queryPaymentResponse->getResponseMessage());
            $this->assertEquals($widgetPaymentRequest->getPartnerReferenceNo(), $queryPaymentResponse->getOriginalPartnerReferenceNo());
        } catch (\Exception $e) {
            $this->fail('Error during QueryPayment execution: ' . $e->getMessage());
        }
    }    
    
    /**
     * Test Widget Cancel Order API
     *
     * @return void
     */
    public function testWidgetCancelOrder(): void
    {
        // Skip test if no API client credentials are available
        if (empty(getenv('X_PARTNER_ID')) || empty(getenv('PRIVATE_KEY'))) {
            $this->markTestSkipped('Skipping test: No API client credentials');
        }
        
        // Create a payment first
        $widgetPaymentRequest = WidgetFixtures::getWidgetPaymentRequest();
        
        try {
            $widgetPaymentResponse = $this->apiInstance->widgetPayment($widgetPaymentRequest);
            $this->assertNotNull($widgetPaymentResponse);
            
            // Test CancelOrder
            $cancelOrderRequest = WidgetFixtures::getWidgetCancelOrderRequest($widgetPaymentRequest);
            $cancelOrderResponse = $this->apiInstance->cancelOrder($cancelOrderRequest);
            
            $this->assertNotNull($cancelOrderResponse);
            $this->assertEquals($widgetPaymentRequest->getPartnerReferenceNo(), $cancelOrderResponse->getOriginalPartnerReferenceNo());
        } catch (\Exception $e) {
            $this->fail('Error during CancelOrder execution: ' . $e->getMessage());
        }

    }

    public function testApplyTokenAuthCodeMustNotContainQueryDelimiters(): void
    {
        // CustomValidation::validate() runs before the API request is built/sent,
        // so this should fail deterministically with validation errors only.
        $request = new ApplyTokenRequest([
            'grantType' => ApplyTokenRequest::GRANT_TYPE_AUTHORIZATION_CODE,
            'authCode' => 'abc123&state=xyz',
            'refreshToken' => '',
        ]);

        try {
            $this->apiInstance->applyToken($request);
            $this->fail('Expected authCode validation error but request succeeded');
        } catch (\Dana\ApiException $e) {
            $this->assertStringContainsString("authcode must not contain", strtolower($e->getMessage()));
        }
    }

    public function testWidgetPaymentRejectsEmptyProductCode(): void
    {
        $request = WidgetFixtures::getWidgetPaymentRequest();
        $request->getAdditionalInfo()->setProductCode('');

        try {
            $this->apiInstance->widgetPayment($request);
            $this->fail('Expected validation error when productCode is empty');
        } catch (\Dana\ApiException $e) {
            $this->assertStringContainsString('productcode', strtolower($e->getMessage()));
        }
    }

    public function testWidgetPaymentRejectsEmptyTerminalType(): void
    {
        $request = WidgetFixtures::getWidgetPaymentRequest();
        $request->getAdditionalInfo()->getEnvInfo()->setTerminalType('');

        try {
            $this->apiInstance->widgetPayment($request);
            $this->fail('Expected validation error when terminalType is empty');
        } catch (\Dana\ApiException $e) {
            $this->assertStringContainsString('terminaltype', strtolower($e->getMessage()));
        }
    }

    public function testWidgetPaymentAllowsEmptyMcc(): void
    {
        $request = WidgetFixtures::getWidgetPaymentRequest();
        $request->getAdditionalInfo()->setMcc('');

        try {
            $this->apiInstance->widgetPayment($request);
        } catch (\Dana\ApiException $e) {
            $msg = strtolower($e->getMessage());
            $this->assertFalse(
                str_contains($msg, 'mcc') && str_contains($msg, 'required'),
                'mcc may be empty for Widget, got: ' . $e->getMessage()
            );
        }
    }

    public function testWidgetPaymentRejectsSandboxAmountOverMax(): void
    {
        $request = WidgetFixtures::getWidgetPaymentRequest();
        $request->setAmount(new \Dana\Widget\v1\Model\Money([
            'value' => '10000000.01',
            'currency' => 'IDR',
        ]));

        try {
            $this->apiInstance->widgetPayment($request);
            $this->fail('Expected validation error when sandbox amount exceeds 10000000');
        } catch (\Dana\ApiException $e) {
            $msg = strtolower($e->getMessage());
            $this->assertStringContainsString('amount', $msg);
            $this->assertStringContainsString('10000000', $e->getMessage());
        }
    }

    public function testWidgetPaymentDefaultsEmptySourcePlatformToIpg(): void
    {
        $request = WidgetFixtures::getWidgetPaymentRequest();
        $request->getAdditionalInfo()->getEnvInfo()->setSourcePlatform('');

        try {
            $this->apiInstance->widgetPayment($request);
        } catch (\Dana\ApiException $e) {
            $msg = strtolower($e->getMessage());
            $this->assertFalse(
                str_contains($msg, 'sourceplatform') && str_contains($msg, 'required'),
                'empty sourcePlatform should default to IPG, got: ' . $e->getMessage()
            );
        }
    }

}
