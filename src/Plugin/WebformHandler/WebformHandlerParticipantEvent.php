<?php

namespace Drupal\webform_participant_event\Plugin\WebformHandler;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Element\WebformMapping;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\webform\Utility\WebformFormHelper;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InsertCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\BeforeCommand;
use Drupal\Component\Utility\NestedArray;

use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionForm;

/**
 * Webform civi handler.
 *
 * @WebformHandler(
 *   id = "participant_participant_event",
 *   label = @Translation("Register participant - Civicrm Event"),
 *   category = @Translation("CiviCRM"),
 *   description = @Translation("Try to find previous submission (for anonumous user) and create participant for an event."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class WebformHandlerParticipantEvent extends WebformHandlerBase {

  /**
   * The webform token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->tokenManager = $container->get('webform.token_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    // Perform bootstrap
    \Drupal::service('civicrm')->initialize();

    $datas = $webform_submission->getData();

    if (!empty($datas['civicrm_id'])) {
      // verifie si les informations de contact sont coherentes
      $contacts = \Civi\Api4\Contact::get(false)
        ->addSelect('id', 'first_name', 'last_name', 'email_primary.email', 'phone_primary.phone')
        ->addWhere('id', '=', $datas['civicrm_id'])
        ->execute();
      if ($contacts->count() != 1) {
        if ($contacts->count() == 0) {
          \Drupal::logger('webform_participant_event')->error('civicrm_id inconnu :' . $datas['civicrm_id'] .
            'nom saisi : ' . $datas['nom'] . '<br>' .
            'prenom saisi : ' . $datas['prenom'] . '<br>' .
            'email saisi: ' . $datas['email'] . '<br>' .
            'telephone saisi: ' . $datas['portable']);
        }
        return;
      }
      if (
        (trim(strtolower($datas['nom']))) != (trim(strtolower($contacts->first()['last_name']))) ||
        (trim(strtolower($datas['prenom']))) != (trim(strtolower($contacts->first()['first_name']))) ||
        (trim(strtolower($datas['email']))) != (trim(strtolower($contacts->first()['email_primary.email'])))
      ) {
        \Drupal::logger('webform_participant_event')->error('Incoherence user :' . $datas['civicrm_id'] .
          'nom saisi : ' . $datas['nom'] . ' Civicrm=> ' . $contacts->first()['last_name'] . '<br>' .
          'prenom saisi : ' . $datas['prenom'] . ' Civicrm=> ' . $contacts->first()['first_name'] . '<br>' .
          'email saisi: ' . $datas['email'] . ' Civicrm=> ' . $contacts->first()['email_primary.email'] . '<br>' .
          'telephone saisi: ' . $datas['portable'] . ' Civicrm=> ' . $contacts->first()['phone_primary.phone']);
        return;
      }
    } else {
      // Pas d'id, recherche par email
      $contacts = \Civi\Api4\Contact::get(false)
        ->addSelect('id', 'first_name', 'last_name', 'email_primary.email', 'phone_primary.phone')
        ->addWhere('email_primary.email', '=', trim($datas['email']))
        ->addWhere('last_name', '=', trim($datas['nom']))
        ->setLimit(25)
        ->execute();
      if ($contacts->count() != 1) {
        \Drupal::logger('webform_participant_event')->error('User inconnu : nom saisi : ' . $datas['nom'] . ' Civicrm=> ' . $contacts->first()['last_name'] . '<br>' .
          'prenom saisi : ' . $datas['prenom'] . ' Civicrm=> ' . $contacts->first()['first_name'] . '<br>' .
          'email saisi: ' . $datas['email'] . ' Civicrm=> ' . $contacts->first()['email_primary.email'] . '<br>' .
          'telephone saisi: ' . $datas['portable'] . ' Civicrm=> ' . $contacts->first()['phone_primary.phone']);
        return;
      } else {
        if (
          (trim(strtolower($datas['nom']))) != (trim(strtolower($contacts->first()['last_name']))) ||
          (trim(strtolower($datas['prenom']))) != (trim(strtolower($contacts->first()['first_name']))) ||
          (trim(strtolower($datas['email']))) != (trim(strtolower($contacts->first()['email_primary.email'])))
        ) {
          \Drupal::logger('webform_participant_event')->error('Incoherence user :' . $datas['civicrm_id'] .
            'nom saisi : ' . $datas['nom'] . ' Civicrm=> ' . $contacts->first()['last_name'] . '<br>' .
            'prenom saisi : ' . $datas['prenom'] . ' Civicrm=> ' . $contacts->first()['first_name'] . '<br>' .
            'email saisi: ' . $datas['email'] . ' Civicrm=> ' . $contacts->first()['email_primary.email'] . '<br>' .
            'telephone saisi: ' . $datas['portable'] . ' Civicrm=> ' . $contacts->first()['phone_primary.phone']);
          return;
        }
      }
      $datas['civicrm_id'] = $contacts->first()['id'];
    }


    $participants = \Civi\Api4\Participant::get(false)
      ->addWhere('event_id', '=', $this->configuration['events'])
      ->addWhere('contact_id', '=', $datas['civicrm_id'])
      ->execute();


    if ($participants->count() == 0) {
      $results = \Civi\Api4\Participant::create(false)
        ->addValue('contact_id', $datas['civicrm_id'])
        ->addValue('event_id', $this->configuration['events']);
    } else {
      $results = \Civi\Api4\Participant::update(false)
        ->addWhere('contact_id', '=', $datas['civicrm_id'])
        ->addWhere('event_id', '=', $this->configuration['events']);
    }
    $date = new \DateTimeImmutable();
    $results->addValue('status_id', $datas[$this->configuration['field_inscrit']] == $this->configuration['field_inscrit_option'] ?
        $this->configuration['field_inscrit_status'] :
        $this->configuration['field_response_status'])
      ->addValue('role_id', [
          1,
        ])
      ->addValue('register_date', $date->format('Y-m-d H:i:s'));

    $customGroups = \Civi\Api4\CustomGroup::get(FALSE)
      ->addSelect('id', 'name', 'custom_field.id', 'custom_field.name', 'custom_field.label', 'custom_field.data_type', 'custom_field.text_length', 'custom_field.option_group_id')
      ->addJoin('CustomField AS custom_field', 'LEFT', ['custom_field.custom_group_id', '=', 'id'])
      ->addWhere('extends_entity_column_value', 'CONTAINS', $this->configuration['events'])
      ->execute();

    foreach ($customGroups as $key => $fname) {
      if (array_search($fname['custom_field.id'], $this->configuration)) {
        $tmp_data = $datas[substr(array_search($fname['custom_field.id'], $this->configuration), 7)];
        if (!empty($tmp_data)) {
          switch ($fname['custom_field.data_type']) {
            case 'String':
              $tmp_data = Xss::filter($tmp_data);
              break;
            case 'Integer':
              $tmp_data = (int) $tmp_data;
              break;
            case 'Float':
              $tmp_data = (float) $tmp_data;
              break;
            case 'Date':
              $tmp_data = new \DateTimeImmutable($tmp_data);
              break;
            case 'Boolean':
              $tmp_data = strtolower($tmp_data) == 'oui' || 'yes' ? 1 : 0;
              break;
          }
          $results->addValue(
            $fname['name'] . '.' . $fname['custom_field.name'],
            // remove 'select_' from configuration variable
            $tmp_data
          );
        }
      }
    }

    /* ->addValue('sortie_Pascuet_Offroad_Center.Arrive_vendredi', strtolower($datas['repas_vendredi']) == 'oui' ? 1 : 0)
        ->addValue('sortie_Pascuet_Offroad_Center.Depart_Lundi', strtolower($datas['petit_dej_lundi']) == 'oui' ? 1 : 0)
        ->addValue('sortie_Pascuet_Offroad_Center.Observations', $datas['observations'])
        ->addValue('sortie_Pascuet_Offroad_Center.Roule_lundi', strtolower($datas['roulage_lundi']) == 'oui' ? 1 : 0)
        ->addValue('sortie_Pascuet_Offroad_Center.groupe', $datas['groupe'])
        ->execute(); */
    $results->execute();
  }

  private function sendMail($to, $subject, $message) {

    $module = 'webform_participant_event';
    $key = 'user_coherence';
    $reply = NULL;
    $send = TRUE;
    $host = \Drupal::request()->getHost();

    $params['subject'] = $subject;
    $params['message'] = $message;

    $mailManager = \Drupal::service('plugin.manager.mail');
    $mailManager->mail($module, $key, $to, null, $params, $reply, $send);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {


    // Perform bootstrap
    \Drupal::service('civicrm')->initialize();
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['#tree'] = true;
    $form['general']['#tree'] = true;
    // Message.
    $form['message'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Message settings'),
    ];
    $form['message']['message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message to be displayed when form is completed'),
      '#default_value' => $this->configuration['message'] ?? 'Merci',
      '#required' => TRUE,
    ];

    // Development.
    $form['development'] = [
      '#type' => 'details',
      '#title' => $this->t('Development settings'),
    ];
    $form['development']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('If checked, every handler method invoked will be displayed onscreen to all users.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['debug'] ?? false,
    ];

    $pages = [];
    $pages[0] = "Select ...";
    foreach ($this->getWebform()->getElementsDecoded() as $key => $field) {
      if ($field['#type'] == 'webform_wizard_page') {
        if (isset($field['civicrm_id'])) {
        $pages[$key] = $field['#title'];
        }
      }
    }
    
    $form['prev_submission'] = [
      '#type' => 'fieldset',
      '#title' => 'Retreive previous submission',
      '#description' => 'Search if a previous submission exist using <b>civicrm_id</b> hidden field.',
    ];
    $form['prev_submission']['check_on_page'] = [
      '#type' => 'select',
      '#title' => $this->t('Recherche de soumission sur la page'),
      '#options' => $pages,
      '#description' => 'Show only pages with an hidden field named <b>civicrm_id</b>.',
      '#access' => count($pages) > 1 ? true : false,
      '#default_value' => $this->configuration['check_on_page'] ?? array_key_first($pages),
    ];


    // $radios[0] = 'Select field...';
    $radios = [];
    foreach ($this->getWebform()->getElementsDecodedAndFlattened() as $key => $field) {
      if ($field['#type'] == 'radios') {
        $radios[$key] = $field['#title'];
      }
    }

    $form['fieldset_participe'] = [
      '#type' => 'fieldset',
      '#title' => 'Participation'
    ];

    $form['fieldset_participe']['field_inscrit'] = [
      '#type' => 'select',
      '#title' => $this->t('Champ validant l\'inscription'),
      '#options' => $radios,
      '#default_value' => $this->configuration['field_inscrit'] ?? array_key_first($radios),
      '#ajax' => [
        'callback' => [$this, 'inscritOptionCallback'], // don't forget :: when calling a class method.
        'disable-refocus' => FALSE, // Or TRUE to prevent re-focusing on the triggering element.
        'event' => 'change',
        'wrapper' => 'option-inscription-field', // This element is updated with this AJAX callback.
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Verifying entry...'),
        ],
      ]
    ];

    $form['fieldset_participe']['status_inscription'] = [
      '#type' => 'webform_flexbox',
      '#align_items' => 'center',
    ];
    $form['fieldset_participe']['status_inscription']['option'] = [
      '#type' => 'select',
      '#title' => $this->t('Valeur indiquant la participation'),
      '#options' => $form_state->getValue('settings')['fieldset_participe']['field_inscrit'] ??
        $this->get_options($form['fieldset_participe']['field_inscrit']['#default_value']),
      '#default_value' => $this->configuration['field_inscrit_option'] ?? 0,
      '#prefix' => '<div id="option-inscription-field">',
      '#suffix' => '</div>',
      // '#access' => false,
    ];

    $form['fieldset_participe']['status_inscription']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status de participation'),
      '#options' => $this->get_status(true),
      '#default_value' => $this->configuration['field_inscrit_status'] ?? 0,
      '#prefix' => '<div id="status-inscription-field">',
      '#suffix' => '</div>',
      // '#access' => false,
    ];

    $form['fieldset_noparticipe'] = [
      '#type' => 'fieldset',
      '#title' => 'Non participation'
    ];

    $form['fieldset_noparticipe']['field_response'] = [
      '#type' => 'select',
      '#title' => $this->t('Champ validant l\'inscription'),
      '#options' => $radios,
      '#default_value' => $this->configuration['field_response'] ?? array_key_first($radios),
      '#ajax' => [
        'callback' => [$this, 'responseOptionCallback'], // don't forget :: when calling a class method.
        'disable-refocus' => FALSE, // Or TRUE to prevent re-focusing on the triggering element.
        'event' => 'change',
        'wrapper' => 'option-response-field', // This element is updated with this AJAX callback.
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Verifying entry...'),
        ],
      ]
    ];
    $form['fieldset_noparticipe']['status_response'] = [
      '#type' => 'webform_flexbox',
      '#align_items' => 'center',
    ];
    $form['fieldset_noparticipe']['status_response']['option'] = [
      '#type' => 'select',
      '#title' => $this->t('Valeur indiquant la non participation'),
      '#options' => $form_state->getValue('settings')['fieldset_noparticipe']['field_response'] ??
        $this->get_options($form['fieldset_noparticipe']['field_response']['#default_value']),
      '#default_value' => $this->configuration['field_response_option'] ?? 0,
      '#prefix' => '<div id="option-response-field">',
      '#suffix' => '</div>',
      // '#access' => false,
    ];

    $form['fieldset_noparticipe']['status_response']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status de non participation'),
      '#options' => $this->get_status(false),
      '#default_value' => $this->configuration['field_response_status'] ?? 0,
      '#prefix' => '<div id="status-response-field">',
      '#suffix' => '</div>',
      // '#access' => false,
    ];

    $events = \Civi\Api4\Event::get(false)
      ->addSelect('title')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('created_date', 'DESC')
      ->execute();
    $evt_select = [];
    foreach ($events as $event) {
      $evt_select[$event['id']] = $event['title'];
    }

    $form['fieldset_mapping'] = [
      '#type' => 'fieldset',
      '#title' => 'Correspondance des attributs',
      '#tree' => true,
    ];

    $form['fieldset_mapping']['events'] = [
      '#type' => 'select',
      '#title' => $this->t('Event ...'),
      '#options' => $evt_select,
      '#default_value' => $this->configuration['events'] ?? array_key_first($evt_select),
      '#ajax' => [
        'callback' => [$this, 'mapping2AjaxCallback'], // don't forget :: when calling a class method.
        //'callback' => [$this, 'myAjaxCallback'], //alternative notation
        'disable-refocus' => FALSE, // Or TRUE to prevent re-focusing on the triggering element.
        'event' => 'change',
        'wrapper' => 'mapping-field', // This element is updated with this AJAX callback.
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Verifying entry...'),
        ],
        '#weight' => 0,
      ]
    ];

    $form['fieldset_mapping']['events_name'] = [
      '#type' => 'hidden',
      '#title' => 'Event system name',
      '#default_value' => $this->configuration['events_name'] ?? $this->get_event_attributs($form, $form_state, true)[1],
    ];


    $webform_fields = $this->get_webform_fields();

    $i = 0;
    foreach ($webform_fields[0] as $key => $fname) {
      $form['fieldset_mapping'][$key] = [
        '#type' => 'webform_flexbox',
        '#align_items' => 'center',
        '#weight' => $i,
      ];

      $form['fieldset_mapping'][$key]['field'] = [
        '#type' => 'textfield',
        '#default_value' => $fname,
        '#disabled' => true,
        '#size' => 15,

      ];
      $form['fieldset_mapping'][$key]['select'] = [
        '#type' => 'select',
        '#options' => $this->get_event_attributs($form, $form_state, true)[0],
        '#default_value' => $this->configuration['select_' . $key] ?? 0,
        '#prefix' => '<div id="select_' . $key . '">',
        '#suffix' => '</div>',
      ];
      $i++;
    }

    // $this->getCustomFields($form, $form_state);
    return $this->setSettingsParents($form);
  }

  private function get_webform_fields() {
    $webform_fields = [];
    $webform_fields_type = [];
    foreach ($this->getWebform()->getElementsDecodedAndFlattened() as $key => $field) {
      switch ($field['#type']) {
        case 'textfield':
        case 'textarea':
        case 'email':
        case 'tel':
        case 'radios':
        case 'checkbox':
        case 'hidden':
          case 'webform_scale':
        // case 'checkboxes':
          $webform_fields[$key] = $field['#title'];
          $webform_fields_type[$key] = $field['#type'];
          break;
      }
    }
    return [$webform_fields, $webform_fields_type];
  }

  private function get_options(string $element) {
    $opt = [];
    foreach ($this->getWebform()->getElementInitialized($element)['#options'] as $key => $value) {
      $opt[$key] = strlen($value) > 12 ? substr($value, 0, 12) . "..." : $value;
    }
    return $opt;
  }

  private function get_status($counted = true) {
    $participantStatusTypes = \Civi\Api4\ParticipantStatusType::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_counted', '=', $counted)
      ->execute();

    $status = [];
    foreach ($participantStatusTypes as $participantStatusType) {
      $status[$participantStatusType['id']] = $participantStatusType['label'];
    }
    return $status;
  }

  private function get_event_attributs($form, $form_state, $first_select = false) {

    if ($form_state instanceof \Drupal\Core\Form\SubformState === true) {
      $selctedOption = $form_state->getValue('fieldset_mapping')['events'] ??
        $form['fieldset_mapping']['events']['#default_value'];
    } else {
      $selctedOption = $form_state->getValue('settings')['fieldset_mapping']['events'] ??
        $form['fieldset_mapping']['events']['#default_value'];
    }

    $customGroups = \Civi\Api4\CustomGroup::get(FALSE)
      ->addSelect('id', 'name', 'custom_field.id', 'custom_field.name', 'custom_field.label', 'custom_field.data_type', 'custom_field.text_length', 'custom_field.option_group_id')
      ->addJoin('CustomField AS custom_field', 'LEFT', ['custom_field.custom_group_id', '=', 'id'])
      ->addWhere('extends_entity_column_value', 'CONTAINS', $selctedOption)
      ->execute();

    if ($first_select) {
      $custom_fields[0] = 'Select value...';
    } else {
      $custom_fields = [];
    }

    foreach ($customGroups as $customGroup) {
      $custom_fields[$customGroup['custom_field.id']] = $customGroup['custom_field.label'];
    }
    return [$custom_fields, $customGroups->first()['name'], $customGroups->first()['id']];
  }



  public function responseOptionCallback(array &$form, FormStateInterface $form_state) {
    $selctedOption = $form_state->getValue('settings')['fieldset_response']['field_response'];

    $elements1 = WebformFormHelper::flattenElements($form);

    $elements1['fieldset_response']['field_response']['option']['#options'] = $this->get_options($selctedOption);
    return $elements1['fieldset_response']['field_response']['option'];
  }

  public function  inscritOptionCallback(array &$form, FormStateInterface $form_state) {
    $selctedOption = $form_state->getValue('settings')['fieldset_participe']['field_inscrit'];

    $elements1 = WebformFormHelper::flattenElements($form);

    $elements1['fieldset_participe']['status_inscription']['option']['#options'] = $this->get_options($selctedOption);
    return $elements1['fieldset_participe']['status_inscription']['option'];
  }

  // Get the value from example select field and fill
  // the textbox with the selected text.
  public function mappingAjaxCallback(array &$form, FormStateInterface $form_state) {
    $info_attrib = $this->get_event_attributs($form, $form_state, true);
    $attrib = $info_attrib[0];

    $elements = WebformFormHelper::flattenElements($form);

    unset($form['settings']['fieldset_mapping']['mapping']);

    $webform_fields = [];
    foreach ($this->getWebform()->getElementsDecodedAndFlattened() as $key => $field) {
      switch ($field['#type']) {
        case 'textfield':
        case 'textarea':
        case 'email':
        case 'tel':
        case 'radios':
          $webform_fields[$key] = $field['#title'];
          break;
      }
    }

    $element = [
      '#type' => 'webform_mapping',
      '#title' => 'Attribut Mapping',
      '#tree' => TRUE,
      '#source__title' => 'Webform',
      '#destination__title' => 'CiviCRM Event',
      '#prefix' => '<div id="mappingfield">',
      '#suffix' => '</div>',
      '#source' => $webform_fields,
      '#destination' => $attrib,
    ];

    // Reconstruit l'element
    // lors du traitement, les données de #destination sont recopiées dans la table
    WebformMapping::processWebformMapping($element, $form_state, $form);

    if (!$form_state->getErrors()) { //only if form has no errors
      $response = new AjaxResponse();
      // $response->addCommand(new BeforeCommand("#mappingfield", $elements['mapping']));
      $response->addCommand(new ReplaceCommand("#mappingfield", $element));
      return $response;
    } else {
      return $elements['fieldset_mapping']['mapping'];
    }
  }


  // Get the value from example select field and fill
  // the textbox with the selected text.
  public function mapping1AjaxCallback(array &$form, FormStateInterface $form_state) {
    $info_attrib = $this->get_event_attributs($form, $form_state, true);
    $attrib = $info_attrib[0];

    $elements = WebformFormHelper::flattenElements($form);

    $elements['fields']['#options'] = $attrib;
    $elements['fields1']['#options'] = $attrib;
    $elements['fields2']['#options'] = $attrib;

    if (!$form_state->getErrors()) { //only if form has no errors
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand("#mapping-field", ($elements['fields'])));
      $response->addCommand(new ReplaceCommand("#mapping1-field", ($elements['fields1'])));
      $response->addCommand(new ReplaceCommand("#mapping2-field", ($elements['fields2'])));
      return $response;
    } else {
      return $elements;
    }
  }

  // Get the value from example select field and fill
  // the textbox with the selected text.
  public function mapping2AjaxCallback(array &$form, FormStateInterface $form_state) {
    $info_attrib = $this->get_event_attributs($form, $form_state, true);
    $attrib = $info_attrib[0];
    $elements = WebformFormHelper::flattenElements($form);
    $event = $form_state->getValue('settings')['fieldset_mapping']['events'];
    $event_config = $this->configuration['events'];
    $response = new AjaxResponse();
    foreach ($this->get_webform_fields()[0] as $key => $field) {
      $elements['fieldset_mapping'][$key]['select']['#options'] = $attrib;
      if ($event == $event_config) {
        // utilise #value et non #default_value
        // voir https://www.drupal.org/project/drupal/issues/2895887
        $elements['fieldset_mapping'][$key]['select']['#value'] = $this->configuration['select_' . $key];
        $elements['fieldset_mapping']['events_name']['#value'] = $this->configuration['events_name'] ?? $info_attrib[1];
      } else {
        $elements['fieldset_mapping'][$key]['select']['#value'] = 0;
        $elements['fieldset_mapping']['events_name']['#value'] = $info_attrib[1];
      }
      $response->addCommand(new ReplaceCommand("#select_" . $key, $elements['fieldset_mapping'][$key]['select']));
    }
    return $response;
  }



  // Get the value from example select field and fill
  // the textbox with the selected text.
  public function myAjaxCallback(array &$form, FormStateInterface $form_state) {
    $selctedOption = $form_state->getValue('settings')['events'];

    $elements = $this->webform->getElementsInitialized();

    $customGroups = \Civi\Api4\CustomGroup::get(TRUE)
      ->addSelect('id', 'name', 'title')
      ->addWhere('extends_entity_column_value', '=', $selctedOption)
      ->addChain(
        'fields',
        \Civi\Api4\CustomField::get(TRUE)
          ->addWhere('custom_group_id', '=', '$id')
      )
      ->execute();

    $form['settings']['fields']['#options'] = [];
    foreach ($customGroups as $customGroup) {
      foreach ($customGroup['fields'] as $field) {
        $form['settings']['fields']['#options'][$field['id']] = $field['label'];
      }
      // do something
    }

    return $form['settings']['fields'];
  }

  // Get the value from example select field and fill
  // the textbox with the selected text.
  public function myAjaxCallback1(array &$form, FormStateInterface $form_state) {
    // Return the prepared textfield.
    $customGroups = \Civi\Api4\CustomGroup::get(TRUE)
      ->addSelect('*', 'custom.*')
      ->addWhere('extends_entity_column_value', '=', 54)
      ->setLimit(25)
      ->execute();
    $form['fields']['#options'] = [];
    foreach ($customGroups as $customGroup) {
      // do something

    }

    return $form['fields'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['message'] = $form_state->getValue('message');
    $this->configuration['debug'] = (bool) $form_state->getValue('debug');
    $this->configuration['field_inscrit'] = $form_state->getValue('fieldset_participe')['field_inscrit'];
    $this->configuration['field_inscrit_option'] = $form_state->getValue('fieldset_participe')['status_inscription']['option'];
    $this->configuration['field_inscrit_status'] = $form_state->getValue('fieldset_participe')['status_inscription']['status'];
    $this->configuration['field_response'] = $form_state->getValue('fieldset_noparticipe')['field_response'];
    $this->configuration['field_response_option'] = $form_state->getValue('fieldset_noparticipe')['status_response']['option'];
    $this->configuration['field_response_status'] = $form_state->getValue('fieldset_noparticipe')['status_response']['status'];
    $this->configuration['events'] = $form_state->getValue('fieldset_mapping')['events'];
    $this->configuration['events_name'] = $form_state->getValue('fieldset_mapping')['events_name'];
    foreach ($this->get_webform_fields()[0] as $key => $field) {
      $e = $form_state->getValue('fieldset_mapping')[$key];
      if ($e['select'] == 0) {
        continue;
      }
      $this->configuration['select_' . $key] = $e['select'];
    }
    $this->configuration['check_on_page'] = $form_state->getValue('prev_submission')['check_on_page'];
  }
  /**
   * {@inheritdoc}
   */
  public function alterElements(array &$elements, WebformInterface $webform) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function overrideSettings(array &$settings, WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {

    /* // check if attribute 'check_existing' exist in Element custom attributes for this Wizard Page element
    // and if is set to true
    if ((isset($form['elements'][$form['progress']['#current_page']]['#attributes']['check_existing'])) &&
      ($form['elements'][$form['progress']['#current_page']]['#attributes']['check_existing'] == true)
    ) { */
      if ((isset($this->configuration['check_on_page'])) &&
          ($this->configuration['check_on_page'] == $form['progress']['#current_page'] )) {
      // check if an submission exist
      $previus_webform_submission = $form_state->getFormObject()->getEntity();
      if ($previus_webform_submission->getState() == 'unsaved') {
        // new submission
        // check if there is a submission for this user (anonymous)

        // Static query
        // https://www.drupal.org/docs/drupal-apis/database-api/static-queries
        $database = \Drupal::database();
        $result = $database->query("SELECT max(sid) as sid FROM `drupal_webform_submission_data` WHERE `webform_id` = '" .
          $this->getWebform()->id() . "' and name = 'civicrm_id' and value = '" . $form_state->getValue('civicrm_id') . "';");
        // last previous submission for this civicrm_id
        if ($result) {
          while ($row = $result->fetchAssoc()) {
            if ($row['sid'] === null) {
              continue;
            }
            // load submission
            $webform_submission = WebformSubmission::load($row['sid']);
            // and save it in form_state
            $form_state->getFormObject()->setEntity($webform_submission);

            foreach ($this->webformSubmission->getData() as $key => $value) {
              // and set previous values
              $form_state->setValue($key, $value);
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function confirmForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $message = $this->configuration['message']['message'];
    $message = $this->replaceTokens($message, $this->getWebformSubmission());
    $this->messenger()->addStatus(Markup::create(Xss::filter($message)), FALSE);
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function preCreate(array &$values) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function postCreate(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function postLoad(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function preDelete(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function postDelete(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webform_submission) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessConfirmation(array &$variables) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function createHandler() {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function updateHandler() {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteHandler() {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function createElement($key, array $element) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function updateElement($key, array $element, array $original_element) {
    $this->debug(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteElement($key, array $element) {
    $this->debug(__FUNCTION__);
  }

  /**
   * Display the invoked plugin method to end user.
   *
   * @param string $method_name
   *   The invoked method name.
   * @param string $context1
   *   Additional parameter passed to the invoked method name.
   */
  protected function debug($method_name, $context1 = NULL) {
    if (!empty($this->configuration['debug'])) {
      $t_args = [
        '@id' => $this->getHandlerId(),
        '@class_name' => get_class($this),
        '@method_name' => $method_name,
        '@context1' => $context1,
      ];
      $this->messenger()->addWarning($this->t('Invoked @id: @class_name:@method_name @context1', $t_args), TRUE);
    }
  }
}
