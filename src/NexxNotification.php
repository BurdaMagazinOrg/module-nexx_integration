<?php

namespace Drupal\nexx_integration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Class NexxNotification.
 *
 * @package Drupal\nexx_integration
 */
class NexxNotification implements NexxNotificationInterface {

  /**
   * The entity storage object for taxonomy terms.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $termStorage;

  /**
   * The entity query object for taxonomy terms.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface
   */
  protected $nodeQuery;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Notify nexxOMNIA video CMS.
   *
   * Notify when channel or actor terms have been updated,
   * or when a video has been created.
   * TODO: entity type manager and node query are not used anymore, remove them.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\Query\QueryFactory $query
   *   The entity query object for taxonomy terms.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The config factory service.
   * @param \GuzzleHttp\Client $http_client
   *   The HTTP client.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    QueryFactory $query,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    Client $http_client
  ) {
    $this->termStorage = $entity_type_manager->getStorage('taxonomy_term');
    $this->nodeQuery = $query->get('node');
    $this->config = $config_factory->get('nexx_integration.settings');
    $this->logger = $logger_factory->get('nexx_integration');
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public function insert($streamtype, $reference_number, $values) {
    if ($streamtype === 'video') {
      throw new \InvalidArgumentException(sprintf('Streamtype cannot be "%s" in insert operation.', $streamtype));
    }
    $response = $this->notificateNexx($streamtype, $reference_number, 'insert', $values);
    if ($response) {
      $this->logger->info("insert @type. Reference number: @reference, values: @values", [
        '@type' => $streamtype,
        '@reference' => $reference_number,
        '@values' => print_r($values, TRUE),
      ]
      );
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function update($streamtype, $reference_number, $values) {
    $response = $this->notificateNexx($streamtype, $reference_number, 'update', $values);
    if ($response) {
      $this->logger->info("update @type. Reference number: @reference, values: @values", [
        '@type' => $streamtype,
        '@reference' => $reference_number,
        '@values' => print_r($values, TRUE),
      ]
      );
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($streamtype, $reference_number, array $values) {
    if ($streamtype === 'video') {
      throw new \InvalidArgumentException(sprintf('Streamtype cannot be "%s" in delete operation.', $streamtype));
    }
    $response = $this->notificateNexx($streamtype, $reference_number, 'delete', $values);
    if ($response) {
      $this->logger->info("delete @type. Reference number: @reference", [
        '@type' => $streamtype,
        '@reference' => $reference_number,
      ]
      );
    }

    return $response;
  }

  /**
   * Send a crud notification to nexx.
   *
   * @param string $streamtype
   *   The data type to update. Possible values are:
   *   - "actor"
   *   - "channel"
   *   - "tag"
   *   - "video".
   * @param string $reference_number
   *   Reference id. In case of streamtype video, this is the nexx ID in all
   *   other cases, this is the corresponding drupal id.
   * @param string $command
   *   CRUD operation. Possible values are:
   *   - "insert"
   *   - "update"
   *   - "delete".
   * @param string[] $values
   *   The values to be set.
   *
   * @return string[]|false
   *   Decoded response.
   */
  protected function notificateNexx(
    $streamtype,
    $reference_number,
    $command,
    array $values = []
  ) {
    $api_url = $this->config->get('nexx_api_url') . 'v3/' . $this->config->get('omnia_id') . '/manage/' . $streamtype . '/add/';
    $api_authkey = $this->config->get('nexx_api_authkey');

    if ($api_url == '' || $api_authkey == '') {
      $this->logger->error("Missing configuration for API Url and/or Installation Code (API Key)");
      drupal_set_message($this->t("Item wasn't exported to Nexx due to missing configuration for API Url and/or Installation Code (API Key)."), 'error');

      return FALSE;
    }

    $response_data = [];
    $headers = [
      'X-Request-Token' => md5($streamtype . $this->config->get('omnia_id') . $this->config->get('api_secret')),
      'X-Request-CID' => $api_authkey,
    ];

    if (isset($values)) {
      $headers += $values;
    }

    try {
      /*
      $this->logger->debug("Send http request to @url with option: @options",
      [
      '@url' => $api_url,
      '@options' => print_r($options, TRUE),
      ]);
       */
      $response = $this->httpClient->request('POST', $api_url, ['headers' => $headers]);
      $response_data = Json::decode($response->getBody()->getContents());

      if ($response_data['state'] !== 'ok') {
        $this->logger->error("Omnia request failed: @error", [
          '@error' => $response_data['info'],
        ]
        );
      }
      else {
        $this->logger->info("Successful notification. Streamtype '@streamtype', command '@command', refnr '@refnr', values '@values'", [
          '@streamtype' => $streamtype,
          '@command' => $command,
          '@refnr' => $reference_number,
          '@values' => print_r($values, TRUE),
        ]
        );
      }
    }
    catch (RequestException $e) {
      $this->logger->error("HTTP request failed: @error", [
        '@error' => $e->getMessage(),
      ]
      );
    }
    return $response_data;
  }

}
