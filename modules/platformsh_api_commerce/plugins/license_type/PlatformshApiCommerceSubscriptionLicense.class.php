<?php
/**
 * @file
 * Platform.sh Subscription license plugin.
 */

class PlatformshApiCommerceSubscriptionLicense extends CommerceLicenseRemoteBase {

  /**
   * Implements EntityBundlePluginProvideFieldsInterface::fields().
   */
  static function fields() {
    $fields = parent::fields();
    // Reference to the subscription.
    $fields['platformsh_license_subscription']['field'] = array(
      'cardinality' => 1,
      'translatable' => FALSE,
      'settings' => array(
        'handler' => 'base',
        'target_type' => 'platformsh_api_resource',
        'target_bundles' => array('subscription'),
      ),
      'module' => 'entityreference',
      'type' => 'entityreference',
    );
    $fields['platformsh_license_subscription']['instance'] = array(
      'label' => t('Subscription'),
      'display' => array(),
    );

    $fields['platformsh_license_cluster']['field'] = array(
      'cardinality' => 1,
      'translatable' => FALSE,
      'module' => 'list',
      'type' => 'list_text',
      'settings' => array(
        'allowed_values' => drupal_map_assoc(\Platformsh\Client\Model\Subscription::$availableClusters),
      ),
    );
    $fields['platformsh_license_cluster']['instance'] = array(
      'label' => t('Project cluster'),
      'required' => TRUE,
      'widget' => array(
        'active' => TRUE,
        'module' => 'options',
        'settings' => array(),
        'type' => 'options_select',
      ),
    );

    $fields['platformsh_license_plan']['field'] = array(
      'cardinality' => 1,
      'translatable' => FALSE,
      'module' => 'list',
      'type' => 'list_text',
      'settings' => array(
        'allowed_values' => drupal_map_assoc(\Platformsh\Client\Model\Subscription::$availablePlans),
      ),
    );
    $fields['platformsh_license_plan']['instance'] = array(
      'label' => t('Project plan'),
      'required' => TRUE,
      'widget' => array(
        'active' => TRUE,
        'module' => 'options',
        'settings' => array(),
        'type' => 'options_select',
      ),
    );

    $fields['platformsh_license_project_title']['field'] = array(
      'cardinality' => 1,
      'translatable' => FALSE,
      'module' => 'text',
      'type' => 'text',
    );
    $fields['platformsh_license_project_title']['instance'] = array(
      'label' => t('Initial project title'),
      'required' => FALSE,
      'display' => array(),
    );

    return $fields;
  }

  /**
   * Implements CommerceLicenseInterface::accessDetails().
   */
  public function accessDetails() {
    $output = array();

    /** @var \PlatformshApiResource $subscription */
    $subscription = $this->wrapper->platformsh_license_subscription->value();
    if ($subscription) {
      $link = $subscription->wrapper()->project_link->value();
      if ($link) {
        $output['project'] = array(
          '#markup' => $link,
        );
      }
    }

    return drupal_render($output);
  }

  /**
   * {@inheritdoc}
   */
  public function synchronize() {
    switch ($this->status) {
      case COMMERCE_LICENSE_PENDING:
        if ($resource = $this->wrapper()->platformsh_license_subscription->value()) {
          $this->synchronizeExistingSubscription($resource);
        }
        else {
          $this->createNewSubscription();
        }
        return TRUE;

      case COMMERCE_LICENSE_REVOKED:
        return $this->deleteSubscription();

      case COMMERCE_LICENSE_EXPIRED:
      default:
        return FALSE;
    }
  }

  /**
   * Synchronize an existing subscription.
   *
   * @param \PlatformshApiResource $subscription_resource
   *   The subscription resource entity.
   */
  protected function synchronizeExistingSubscription(\PlatformshApiResource $subscription_resource) {
    $failed_statuses = array(
      \Platformsh\Client\Model\Subscription::STATUS_FAILED,
      \Platformsh\Client\Model\Subscription::STATUS_DELETED,
      \Platformsh\Client\Model\Subscription::STATUS_SUSPENDED,
    );

    /** @var \Platformsh\Client\Model\Subscription $subscription */
    $subscription = $subscription_resource->source();

    $subscription->refresh();
    if ($subscription->isActive()) {
      $this->wrapper()->sync_status = COMMERCE_LICENSE_SYNCED;
    }
    elseif ($subscription->isPending()) {
      $this->wrapper()->sync_status = COMMERCE_LICENSE_SYNC_FAILED_RETRY;
    }
    elseif (in_array($subscription->getStatus(), $failed_statuses)) {
      $this->wrapper()->sync_status = COMMERCE_LICENSE_SYNC_FAILED;
    }

    $this->save();
  }

  /**
   * Create a new subscription.
   *
   * @throws \RuntimeException
   *   If the subscription cannot be created.
   */
  protected function createNewSubscription() {
    $client = platformsh_api_client();
    $subscription = $client->createSubscription(
      $this->wrapper()->platformsh_license_cluster->value(),
      $this->wrapper()->platformsh_license_plan->value(),
      $this->wrapper()->platformsh_license_project_title->value() ?: NULL,
      NULL,
      NULL,
      array(
        'uri' => url('platformsh-api/callback/' . $this->license_id, array(
          'absolute' => TRUE,
          'query' => array(
            'token' => drupal_get_token(),
          ),
        )),
      )
    );
    platformsh_api_save_resources(array($subscription), 'subscription', FALSE, $this->wrapper()->owner->value());

    $resource = platformsh_api_load_resource_by_external_id($subscription->id, 'subscription');
    if (!$resource) {
      throw new \RuntimeException('Failed to create subscription');
    }

    $this->wrapper()->platformsh_license_subscription = $resource;

    $this->save();
  }

  /**
   * Delete a subscription.
   *
   * @return bool
   *   Whether the subscription was successfully deleted.
   */
  protected function deleteSubscription() {
    if ($resource = $this->wrapper()->platformsh_license_subscription->value()) {
      try {
        $resource->source()->delete();
        $this->wrapper()->sync_status = COMMERCE_LICENSE_SYNCED;
      } catch (\GuzzleHttp\Exception\BadResponseException $e) {
        $this->wrapper()->sync_status = COMMERCE_LICENSE_SYNC_FAILED;
      }

      $this->save();
      return TRUE;
    }

    return FALSE;
  }
}
