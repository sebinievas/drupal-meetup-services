<?php

namespace Drupal\broadridge\Form;

use Drupal\broadridge\Client;
use Drupal\broadridge\ClientException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Creates authorization form for Broadridge API.
 */
class SettingsForm extends ConfigFormBase {

  const CONSUMER_KEY_LENGTH = 85;

  /**
   * The Broadridge API client.
   *
   * @var \Drupal\broadridge\Client
   */
  protected $broadridgeClient;

  /**
   * The sevent dispatcher service..
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $eventDispatcher;

  /**
   * The state keyvalue collection.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  protected $logger;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\broadridge\Client $broadridge_client
   *   The factory for configuration objects.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   The logger factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Client $broadridge_client, StateInterface $state, EventDispatcherInterface $event_dispatcher) {
    parent::__construct($config_factory);
    $this->broadridgeClient = $broadridge_client;
    $this->state = $state;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('broadridge.client'),
      $container->get('state'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'broadridge_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'broadridge.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // We're not actually doing anything with this, but may figure out
    // something that makes sense.
    $config = $this->config('broadridge.settings');

    $form['creds'] = [
      '#title' => $this->t('API Connection Settings'),
      '#type' => 'details',
      '#open' => TRUE,
      '#description' => $this->t('Supply credentials to connect'),
    ];

    $form['creds']['access_token'] = [
      '#title' => $this->t('API Token'),
      '#type' => 'textfield',
      '#description' => $this->t('Token used to authenticate api calls.'),
      '#required' => TRUE,
      '#default_value' => $this->broadridgeClient->getAccessToken(),
    ];
    $form['creds']['api_endpoint'] = [
      '#title' => $this->t('Broadridge API endpoint'),
      '#type' => 'textfield',
      '#description' => $this->t('Enter the broadridge partner endpoint URL. Default is https://mp-advisor.marketpower.com/rest/partner. Do not include trailing slash.'),
      '#required' => TRUE,
      '#default_value' => $this->broadridgeClient->getApiEndPoint(),
    ];
    $form['creds']['api_version'] = [
      '#title' => $this->t('API Version'),
      '#type' => 'textfield',
      '#default_value' => $this->broadridgeClient->getApiVersion(),
      '#description' => $this->t('Enter the API version to use. Default is 2.5'),
      '#required' => TRUE,
    ];

    $form = parent::buildForm($form, $form_state);
    $form['creds']['actions'] = $form['actions'];
    unset($form['actions']);
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    if (empty($form_state->getErrors())) {
      // test our connection
      $this->broadridgeClient->setAccessToken($values['access_token']);
      $this->broadridgeClient->setApiEndpoint($values['api_endpoint']);
      $this->broadridgeClient->setApiVersion($values['api_version']);

      try {
        $this->broadridgeClient->apiCall('/inventory.json?page=1');
      } catch (ClientException $e) {
        $form_state->setError(null, 'Unable to get valid response from broadridge API');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // react upon successful submission
  }

}
