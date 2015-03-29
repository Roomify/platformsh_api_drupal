<?php
/**
 * @file
 * Page callbacks for the Platform.sh API module.
 */

/**
 * Configuration form.
 */
function platformsh_api_config_form($form, &$form_state) {
  $form['platformsh_api_token'] = array(
    '#type' => 'textfield',
    '#title' => t('API token'),
    '#description' => t('You can get an API token from your Platform.sh account settings.'),
    '#required' => TRUE,
  );

  if (variable_get('platformsh_api_token')) {
    $form['platformsh_api_token']['#required'] = FALSE;
    $form['platformsh_api_token']['#description'] .= '<br />'
      . t('Leave blank to use the already set token.');
  }

  return system_settings_form($form);
}

/**
 * Validate the configuration form.
 */
function platformsh_api_config_form_validate(&$form, &$form_state) {
  $api_token = variable_get('platformsh_api_token', $form_state['values']['platformsh_api_token']);
  if (!empty($api_token)) {
    if (platformsh_api_validate_token($api_token)) {
      drupal_set_message(t('The API token was successfully validated.'));
    }
    else {
      form_set_error('platformsh_api_token', t('Invalid API token'));
      return;
    }
  }

  if (empty($form_state['values']['platformsh_api_token'])) {
    unset($form_state['values']['platformsh_api_token']);
  }
}

/**
 * Page callback for listing subscriptions.
 */
function platformsh_api_list_subscriptions() {
  $view = views_get_view('platformsh_api_subscriptions');
  $view->override_path = current_path();

  $output = array(
    'refresh_form' => drupal_get_form('platformsh_api_refresh_subscriptions_form'),
    'list' => array(
      '#markup' => $view->preview('embed_1'),
    ),
  );

  return $output;
}