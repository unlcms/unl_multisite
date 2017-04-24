<?php

namespace Drupal\unl_multisite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure book settings for this site.
 */
class UnlMultisiteAdd extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unl_multisite_site_add';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['site_path'] = array(
      '#type' => 'textfield',
      '#title' => t('New site path'),
      '#description' => t('Relative url for the new site.'),
      '#default_value' => 'newsite',
      '#required' => TRUE,
    );
    $form['clone_from_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Site ID to clone'),
      '#description' => t('The new site will be a clone of an existing site.'),
      '#default_value' => '',
      '#required' => FALSE,
    );
    $form['clean_url'] = array(
      '#type' => 'checkbox',
      '#title' => t('Use clean URLs'),
      '#description' => t("Unless you have some reason to think your site won't support this, leave it checked."),
      '#default_value' => 1,
    );
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Create site'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $form_state->setValue('site_path', $this->validatePath($form, $form_state));

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $site_path = $form_state->getValue('site_path');
    $clean_url = $form_state->getValue('clean_url');
    $clone_from_id = $form_state->getValue('clone_from_id');

    if (empty($clone_from_id)) {
      $clone_from_id = NULL;
    }

    // Sanitize submitted URLs
    $site_path = explode('/', $site_path);
    foreach ($site_path as $key => $url_part) {
      $url_part = strtolower($url_part);
      $url_part = preg_replace('/[^a-z0-9]/', '-', $url_part);
      $url_part = preg_replace('/-+/', '-', $url_part);
      $url_part = preg_replace('/(^-)|(-$)/', '', $url_part);
      $site_path[$key] = $url_part;
    }
    $site_path = implode('/', $site_path);

    $id = db_insert('unl_sites')
      ->fields(array(
        'site_path' => $site_path,
        'clean_url' => intval($clean_url),
        'db_prefix' => 'placeholder'.time(),
        'clone_from_id' => $clone_from_id,
      ))
      ->execute();

    // Replace the db_prefix placeholder with s+site_id e.g. s182
    db_update('unl_sites')
      ->fields(array(
        'db_prefix' => 's'.$id,
      ))
      ->condition('site_id', $id, '=')
      ->execute();

    drupal_set_message(t('The site @uri has been scheduled for creation. Run unl_multisite/cron.php to finish install.', array('@uri' => $uri)));

    $url = \Drupal\Core\Url::fromRoute('unl_multisite.sites');
    return $form_state->setRedirectUrl($url);
  }

  /**
   * Custom function to validate and correct a path submitted in a form.
   */
  function validatePath(array $form, FormStateInterface $form_state) {
    $site_path = trim($form_state->getValue('site_path'));

    if (substr($site_path, 0, 1) == '/') {
      $site_path = substr($site_path, 1);
    }
    if (substr($site_path, -1) != '/') {
      $site_path .= '/';
    }

    $site_path_parts = explode('/', $site_path);
    $first_directory = array_shift($site_path_parts);
    if (in_array($first_directory, array('core', 'includes', 'misc', 'modules', 'profiles', 'scripts', 'sites', 'themes', 'vendor'))) {
      $form_state->setErrorByName('site_path', t('Drupal site paths must not start with the @first_directory directory.', array('@first_directory' => $first_directory)));
    }

    if ($form['#form_id'] != 'unl_site_create') {
      if (substr(strtolower($form['site_path']['#default_value']), 0, strlen($site_path)) ==  strtolower($site_path)) {
        $form_state->setErrorByName('site_path', t('New path cannot be parent directory of current path.'));
      }

      if (substr(strtolower($site_path), 0, strlen($form['site_path']['#default_value'])) ==  strtolower($form['site_path']['#default_value'])) {
        $form_state->setErrorByName('site_path', t('New path cannot be sub directory of current path.'));
      }
    }

    $site = db_select('unl_sites', 's')
      ->fields('s', array('site_path'))
      ->condition('site_path', $site_path)
      ->execute()
      ->fetch();

    $alias = db_select('unl_sites_aliases', 'a')
      ->fields('a', array('path'))
      ->condition('path', $site_path)
      ->execute()
      ->fetch();

    if ($site || $alias) {
      $form_state->setErrorByName('site_path', t('Path already in use.'));
    }

    return $site_path;
  }

}
