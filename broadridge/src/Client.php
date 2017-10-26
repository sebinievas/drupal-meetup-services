<?php

namespace Drupal\broadridge;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Broadridge API Client
 */
class Client {

  /**
   * Reponse object.
   *
   * @var \GuzzleHttp\Psr7\Response
   */
  public $response;

  /**
   * GuzzleHttp client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Broadridge config entity.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The cache service.
   *
   * @var Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The JSON serializer service.
   *
   * @var \Drupal\Component\Serialization\Json
   */
  protected $json;

  const CACHE_LIFETIME = 300;
  const LONGTERM_CACHE_LIFETIME = 86400;

  /**
   * Constructor which initializes the consumer.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The GuzzleHttp Client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   * @param \Drupal\Component\Serialization\Json $json
   *   The JSON serializer service.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory,  StateInterface $state, CacheBackendInterface $cache, Json $json) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->config = $this->configFactory->get('broadridge.settings');
    $this->state = $state;
    $this->cache = $cache;
    $this->json = $json;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function apiCall($path, array $params = [], $method = 'GET') {
    $this->getAccessToken();

    $url = $this->getApiEndPoint() . $path;

    try {
      $this->response = new ClientResponse($this->apiHttpRequest($url, $params, $method));
    }
    catch (RequestException $e) {
      // RequestException gets thrown for any response status but 2XX.
      $this->response = $e->getResponse();

      // Any exceptions besides 401 get bubbled up.
      if (!$this->response || $this->response->getStatusCode() != 401) {
        throw new ClientException($this->response, $e->getMessage(), $e->getCode(), $e);
      }
    }

    if ($this->response->getStatusCode() == 401) {
      try {
        $this->response = new ClientResponse($this->apiHttpRequest($url, $params, $method));
      }
      catch (RequestException $e) {
        $this->response = $e->getResponse();
        throw new ClientException($this->response, $e->getMessage(), $e->getCode(), $e);
      }
    }

    if (empty($this->response)
      || ((int) floor($this->response->getStatusCode() / 100)) != 2) {
      throw new ClientException($this->response, 'Unknown error occurred during API call');
    }

    return $this->response;
  }

  /**
   * Private helper to issue an Broadridge API request.
   *
   * @param string $url
   *   Fully-qualified URL to resource.
   *
   * @param array $params
   *   Parameters to provide.
   * @param string $method
   *   Method to initiate the call, such as GET or POST.  Defaults to GET.
   *
   * @return GuzzleHttp\Psr7\Response
   *   Response object.
   */
  protected function apiHttpRequest($url, array $params, $method) {
    if (!$this->getAccessToken()) {
      throw new \Exception('Missing API Token');
    }

    $headers = [
      'Authorization' => $this->getAccessToken(),
      'Accept' => 'application/json;version=' . $this->getApiVersion(),
    ];
    $data = NULL;
    if (!empty($params)) {
      $data = http_build_query($params);
    }
    return $this->httpRequest($url, $data, $headers, $method);
  }

  /**
   * Make the HTTP request. Wrapper around drupal_http_request().
   *
   * @param string $url
   *   Path to make request from.
   * @param string $data
   *   The request body.
   * @param array $headers
   *   Request headers to send as name => value.
   * @param string $method
   *   Method to initiate the call, such as GET or POST.  Defaults to GET.
   *
   * @throws RequestException
   *   Request exxception.
   *
   * @return GuzzleHttp\Psr7\Response
   *   Response object.
   */
  protected function httpRequest($url, $data = NULL, array $headers = [], $method = 'GET') {
    // Build the request, including path and headers. Internal use.
    if ($data) {
      $url .= '?' . $data;
    }
    return $this->httpClient->$method($url, ['headers' => $headers, 'body' => null]);
  }

  /**
   * Extract normalized error information from a RequestException.
   *
   * @param RequestException $e
   *   Exception object.
   *
   * @return array
   *   Error array with keys:
   *   * message
   *   * errorCode
   *   * fields
   */
  protected function getErrorData(RequestException $e) {
    $response = $e->getResponse();
    $response_body = $response->getBody()->getContents();
    $data = $this->json->decode($response_body);
    if (!empty($data[0])) {
      $data = $data[0];
    }
    return $data;
  }

  /**
   * Get the API end point for a given type of the API.
   *
   * @return string
   *   Complete URL endpoint for API access.
   */
  public function getApiEndPoint() {
    return $this->state->get('broadridge.api_endpoint');
  }

  /**
   * Set Broadridge API endpoint.
   *
   * @param string $endpoint
   *   Access token from Broadridge.
   */
  public function setApiEndpoint($endpoint) {
    $this->state->set('broadridge.api_endpoint', $endpoint);
    return $this;
  }

  /**
   * Wrapper for config rest_api_version.version
   */
  public function getApiVersion() {
    return $this->state->get('broadridge.api_version');
  }

  /**
   * Set the api version.
   *
   * @param string $version
   *   API Version to use with the Broadridge API.
   */
  public function setApiVersion($version) {
    $this->state->set('broadridge.api_version', $version);
    return $this;
  }

  /**
   * Get the access token.
   */
  public function getAccessToken() {
    $access_token = $this->state->get('broadridge.access_token');
    return isset($access_token) && Unicode::strlen($access_token) !== 0 ? $access_token : FALSE;
  }

  /**
   * Set the access token.
   *
   * @param string $token
   *   Access token from Broadridge.
   */
  public function setAccessToken($token) {
    $this->state->set('broadridge.access_token', $token);
    return $this;
  }
}
