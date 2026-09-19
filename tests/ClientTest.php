<?php

namespace Picqer\BolRetailerV10\Tests;

use GuzzleHttp\Exception\ClientException as GuzzleClientException;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Picqer\BolRetailerV10\Client;
use GuzzleHttp\Client as HttpClient;
use Picqer\BolRetailerV10\Model\AbstractModel;
use Picqer\BolRetailerV10\Model\OrderItem;
use Picqer\BolRetailerV10\Model\Profile;
use Picqer\BolRetailerV10\Model\WorkingDay;

#[AllowMockObjectsWithoutExpectations]
class ClientTest extends TestCase
{

    /** @var Client */
    private $client;

    /** @var HttpClient */
    private $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClient::class);
        $this->client = new Client();
        $this->client->setHttp($this->httpClientMock);

        $this->authenticateByClientCredentials();
    }

    protected function authenticateByClientCredentials()
    {
        $rawResponse = file_get_contents(__DIR__ . '/Fixtures/http/200-token');

        $response = Message::parseResponse($rawResponse);

        $httpClientMock = $this->createMock(HttpClient::class);

        $credentials = base64_encode('secret_id' . ':' . 'somesupersecretvaluethatshouldnotbeshared');
        $httpClientMock->method('request')->with('POST', 'https://login.bol.com/token', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ])->willReturn($response);

        // use the HttpClient mock created in this method for authentication, put the original one back afterwards
        $prevHttpClient = $this->client->getHttp();
        $this->client->setHttp($httpClientMock);

        $this->client->authenticateByClientCredentials('secret_id', 'somesupersecretvaluethatshouldnotbeshared');

        $this->client->setHttp($prevHttpClient);
    }

    public function testMethodReturnsModel()
    {
        $response = Message::parseResponse(file_get_contents(__DIR__ . '/Fixtures/http/200-order'));
        $this->httpClientMock->method('request')->willReturn($response);

        $order = $this->client->getOrder('test');
        $this->assertInstanceOf(AbstractModel::class, $order);
    }

    public function testMethodReturnsNullForNoContentSuccessResponse()
    {
        $response = Message::parseResponse(implode("\r\n", [
            'HTTP/1.1 204 No Content',
            'Content-Type: application/vnd.retailer.v11+json',
            '',
            '',
        ]));
        $this->httpClientMock->method('request')->willReturn($response);

        $this->assertNull($this->client->getNotForSaleReasons('test'));
    }

    public function testMethodUnwrapsMonoFieldResponse()
    {
        $response = Message::parseResponse(file_get_contents(__DIR__ . '/Fixtures/http/200-reduced-orders'));
        $this->httpClientMock->method('request')->willReturn($response);

        $reducedOrders = $this->client->getOrders();
        $this->assertIsArray($reducedOrders);
    }

    public function testMethodUnwrapsMonoFieldResponse404ToEmptyArray()
    {
        $response = Message::parseResponse(file_get_contents(__DIR__ . '/Fixtures/http/404-not-found'));
        $clientException = new GuzzleClientException(
            'BaseClient error',
            new Request('POST', 'dummy'),
            $response
        );

        $this->httpClientMock->method('request')->willThrowException($clientException);

        $deliveryOptions = $this->client->getDeliveryOptions([]);

        $this->assertEquals([], $deliveryOptions);
    }

    public function testMethodWrapsScalarArgumentToMonoFieldRequest()
    {
        $body = null;
        $this->httpClientMock->method('request')->with('POST')
            ->willReturnCallback(function ($method, $uri, $options) use (&$body) {
                $body = $options['body'] ?? '';
                return Message::parseResponse(file_get_contents(__DIR__ . '/Fixtures/http/202-offers-export'));
            });

        $expectedBody = json_encode([
            'format' => 'CSV'
        ]);

        $this->client->postOfferExport('CSV');

        $this->assertEquals($expectedBody, $body);
    }

    public function testMethodWrapsArrayArgumentToMonoFieldRequest()
    {
        $body = null;
        $this->httpClientMock->method('request')->with('POST')
            ->willReturnCallback(function ($method, $uri, $options) use (&$body) {
                $body = $options['body'] ?? '';
                return Message::parseResponse(file_get_contents(__DIR__ . '/Fixtures/http/200-delivery-options'));
            });

        $orderItems = array_map(function ($id) {
            $orderItem = new OrderItem();
            $orderItem->orderItemId = $id;
            return $orderItem;
        }, ['1', '2', '3']);

        $expectedBody = json_encode([
            'orderItems' => array_map(function ($id) {
                return ['orderItemId' => $id];
            }, ['1', '2', '3'])
        ]);

        $this->client->getDeliveryOptions($orderItems);

        $this->assertEquals($expectedBody, $body);
    }

    public function testMethodWithMissingFieldDueToEmptyArrayReturnsEmptyArray()
    {
        $response = Message::parseResponse(file_get_contents(__DIR__ . '/Fixtures/http/200-reduced-orders-empty'));
        $this->httpClientMock->method('request')->willReturn($response);

        $reducedOrders = $this->client->getOrders();
        $this->assertEquals([], $reducedOrders);
    }

    public function testMethodPassesReferencedOptionalQueryParameters()
    {
        $uri = null;
        $query = null;
        $this->httpClientMock->method('request')
            ->willReturnCallback(function ($method, $requestUri, $options) use (&$uri, &$query) {
                $uri = $requestUri;
                $query = $options['query'] ?? [];

                return Message::parseResponse(implode("\r\n", [
                    'HTTP/1.1 200 OK',
                    'Content-Type: application/vnd.economic-operator.v1+json',
                    '',
                    '{"operators":[],"page":{"pageSize":25,"pageNumber":2,"numberOfPages":1,"totalCount":0}}',
                ]));
            });

        $result = $this->client->getAllEconomicOperators('John', 2, 25);

        $this->assertSame('https://api.bol.com/retailer/economic-operators', $uri);
        $this->assertSame([
            'name' => 'John',
            'page' => 2,
            'page-size' => 25,
        ], $query);
        $this->assertEquals(2, $result->page->pageNumber);
    }

    public function testMethodUnwrapsDeliveryPromiseProfilesResponse()
    {
        $response = Message::parseResponse(implode("\r\n", [
            'HTTP/1.1 200 OK',
            'Content-Type: application/vnd.delivery-promise.v1+json',
            '',
            '{"profiles":[{"profileId":"b864f14f-fbe3-45d2-b09b-3e4c4ebe5b67","name":"Default","isDefault":true,"workingDays":[{"dayOfWeek":"MONDAY","isDeliveryDay":true,"latestOrderTime":"23:00","minCarrierDeliveryDays":1,"maxCarrierDeliveryDays":2}]}]}',
        ]));
        $this->httpClientMock->method('request')->willReturn($response);

        $profiles = $this->client->getDeliveryPromiseProfiles();

        $this->assertIsArray($profiles);
        $this->assertInstanceOf(Profile::class, $profiles[0]);
        $this->assertEquals('Default', $profiles[0]->name);
        $this->assertInstanceOf(WorkingDay::class, $profiles[0]->workingDays[0]);
        $this->assertEquals('MONDAY', $profiles[0]->workingDays[0]->dayOfWeek->value);
    }
}
