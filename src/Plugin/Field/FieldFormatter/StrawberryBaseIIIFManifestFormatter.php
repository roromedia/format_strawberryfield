<?php

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\format_strawberryfield\Tools\IiifUrlValidator;
use Drupal\Core\Access\AccessResult;

/**
 * StrawberryBaseFormatter base class for SBF/JSON based formatters.
 */
abstract class StrawberryBaseIIIFManifestFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    /** @var static $plugin */
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->setEntityTypeManager($container->get('entity_type.manager'),);
    return $plugin;
  }


  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *
   * @return $this
   */
  protected function setEntityTypeManager(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
    return $this;
  }


  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'mediasource' => [
          'metadataexposeentity' => 'metadataexposeentity',
        ],
        'main_mediasource' => 'metadataexposeentity',
        'metadataexposeentity_source' => NULL,
        'manifestnodelist_json_key_source' => 'isrelatedto',
        'manifesturl_json_key_source' => 'iiifmanifest',
        'metadataexposeentity_source_required' => TRUE,
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {

    // Allow Extended Plugins to opt out by making this not required.
    $iiifrequired = $this->getSetting('metadataexposeentity_source_required');
    $settings_form = parent::settingsForm($form, $form_state);
    $entity = NULL;
    if ($this->getSetting('metadataexposeentity_source')) {
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
    }
    $options_for_mainsource = is_array(
      $this->getSetting('mediasource')
    ) && !empty($this->getSetting('mediasource')) ? $this->getSetting(
      'mediasource'
    ) : self::defaultSettings()['mediasource'];

    if (($triggering_element = $form_state->getTriggeringElement(
      )) && isset($triggering_element['#ajax']['callback'])) {
      // We are getting the actual checkbox value pressed in the parents array.
      // so we need to slice by 1 at the end.
      // if Ajax class of the triggering element is this class then process
      if ($triggering_element['#ajax']['callback'][0] == get_class($this)) {
        $parents = array_slice($triggering_element['#parents'], 0, -1);
        $options_for_mainsource = $form_state->getValue($parents);
      }
    }
    $all_options_form_source = [
      'metadataexposeentity' => $this->t(
        'A IIIF Manifest generated by a Metadata Display template exposed Endpoint'
      ),
      'manifesturl' => $this->t(
        'Strawberryfield JSON Key with one or more Manifest URLs'
      ),
      'manifestnodelist' => $this->t(
        'Strawberryfield JSON Key with one or more Node IDs or UUIDs'
      ),
    ];
    $options_for_mainsource = array_filter($options_for_mainsource);
    $options_for_mainsource = array_intersect_key(
      $options_for_mainsource,
      $all_options_form_source
    );

    // Define #ajax callback.
    $ajax = [
      'callback' => [get_class($this), 'ajaxCallbackMainSource'],
      'wrapper' => 'main-mediasource-ajax-container',
    ];
    // Because main media source needs to update its choices based on
    // Media Source checked options, we need to recalculate its default
    // Value also.
    $default_value_main_mediasource = ($this->getSetting(
        'main_mediasource'
      ) && array_key_exists(
        $this->getSetting('main_mediasource'),
        $options_for_mainsource
      )) ? $this->getSetting('main_mediasource') : reset(
      $options_for_mainsource
    );

    $settings_form = $settings_form + [
        'mediasource' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Source for your IIIF Manifest URLs. @optional',[
            '@optional' => $iiifrequired ? '(required)' : '(optional)',
          ]),
          '#description' => t('When using IIIF manifests as source, alternate JSON Key(s) embargo settings and JSON Key(s) where media needs to exists are not going to be respected automatically. Those need to be logically programmed via Twig at the Metadata Display Entity (template) that generates the manifest. Means no embargo settings (upload keys) for this formatter will be carried/passed to the template.'),
          '#options' => $all_options_form_source,
          '#default_value' => $this->getSetting('mediasource'),
          '#required' => $iiifrequired,
          '#attributes' => [
            'data-formatter-selector' => 'mediasource',
          ],
          '#ajax' => $ajax,
        ],
        'main_mediasource' => [
          '#type' => 'select',
          '#title' => $this->t(
            'Select which Source will be handled as the primary one.'
          ),
          '#options' => $options_for_mainsource,
          '#default_value' => $default_value_main_mediasource,
          '#required' => FALSE,
          '#prefix' => '<div id="main-mediasource-ajax-container">',
          '#suffix' => '</div>',
        ],
        'metadataexposeentity_source' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'metadataexpose_entity',
          '#title' => $this->t(
            'Select which Exposed Metadata Endpoint will generate the Manifests'
          ),
          '#description' => $this->t(
            'This value is used for Metadata Exposed Entities and also for Node Lists as Processing source for IIIF Manifests'
          ),
          '#selection_handler' => 'default',
          '#validate_reference' => TRUE,
          '#default_value' => $entity,
          '#states' => [
            [
              'visible' => [
                ':input[data-formatter-selector="mediasource"][value="metadataexposeentity"]' => ['checked' => TRUE],
              ]
            ],
            [
              'visible' => [
                ':input[data-formatter-selector="mediasource"][value="manifestnodelist"]' => ['checked' => TRUE],
              ]
            ]
          ],
        ],
        'manifesturl_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more IIIF manifest URLs. URLs can be external.'
          ),
          '#default_value' => $this->getSetting('manifesturl_json_key_source'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="manifesturl"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'manifestnodelist_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more Nodes. Values can be either NODE IDs (Integers) or UUIDs (Strings). But all of the same type.'
          ),
          '#default_value' => $this->getSetting(
            'manifestnodelist_json_key_source'
          ),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="manifestnodelist"]' => ['checked' => TRUE],
            ],
          ],
        ]
      ];
    return $settings_form;
  }

  /**
   * Ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   An associative array containing entity reference details element.
   */
  public static function ajaxCallbackMainSource(
    array $form,
    FormStateInterface $form_state
  ) {
    $form_parents = $form_state->getTriggeringElement()['#array_parents'];
    $form_parents = array_slice($form_parents, 0, -2);
    $form_parents[] = 'main_mediasource';
    return NestedArray::getValue($form, $form_parents);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $main_mediasource = $this->getSetting(
      'main_mediasource'
    ) ? $this->getSetting('main_mediasource') : NULL;
    if ($this->getSetting('mediasource') && is_array($this->getSetting('mediasource'))) {
      $mediasource = $this->getSetting('mediasource');
      foreach ($mediasource as $source => $enabled) {
        $on = (string)$enabled;
        if ($on == "metadataexposeentity") {
          $entity = NULL;
          if ($this->getSetting('metadataexposeentity_source')) {
            $entity = $this->entityTypeManager->getStorage(
              'metadataexpose_entity'
            )->load($this->getSetting('metadataexposeentity_source'));
            if ($entity) {
              $label = $entity->label();
              $summary[] = $this->t(
                'IIIF Manifest generated by the "%metadatadisplayentity" Metadata Data Expose Endpoint%primary.',
                [
                  '%metadatadisplayentity' => $label,
                  '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
                ]
              );
            }
            else {
              $summary[] = $this->t(
                'IIIF Manifest generated by a non existing "%metadatadisplayentity" Metadata Data Expose Endpoint%primary. Please correct this.',
                [
                  '%metadatadisplayentity' => $this->getSetting(
                    'metadataexposeentity_source'
                  ),
                  '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
                ]
              );
            }
          }
          else {
            $summary[] = $this->t(
              'IIIF Manifest generated by the Metadata Data Expose Endpoint%primary but none set. Please setup this correctly',
              [
                '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
              ]
            );
          }
          continue 1;
        }
        if ($on == "manifesturl") {
          $summary[] = $this->t(
            'IIIF Manifest URL fetched from JSON "%manifesturl_json_key_source" key%primary.',
            [
              '%manifesturl_json_key_source' => $this->getSetting(
                'manifesturl_json_key_source'
              ),
              '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
            ]
          );
          continue 1;
        }
        if ($on == "manifestnodelist") {
          $summary[] = $this->t(
            'IIIF Manifest generated from Node IDs fetched from JSON "%manifestnodelist_json_key_source" key%primary.',
            [
              '%manifestnodelist_json_key_source' => $this->getSetting(
                'manifestnodelist_json_key_source'
              ),
              '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
            ]
          );
          continue 1;
        }
      }
    }
    else {
      $summary[] = $this->t('This formatter still needs to be setup');
    }
    return $summary;
  }


  /**
   * @param int                                       $delta
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param array                                     $elements
   * @param array                                     $jsondata
   * @param string                                    $htmlid
   * @param string                                    $viewer_drupal_settings_key
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function fetchIIIF(int $delta, FieldItemListInterface $items, array &$elements, array $jsondata, string $htmlid, string $viewer_drupal_settings_key) {
    $mediasource = is_array($this->getSetting('mediasource'))
      ? $this->getSetting('mediasource') : [];
    $main_mediasource = $this->getSetting('main_mediasource');
    $nodeuuid = $items->getEntity()->uuid();
    // If legacy/migration/missing config this will be empty?
    $manifests = [];
      foreach ($mediasource as $iiifsource) {
        $pagestrategy = (string) $iiifsource;
        switch ($pagestrategy) {
          case 'metadataexposeentity':
            $manifests['metadataexposeentity']
              = $this->processManifestforMetadataExposeEntity(
              $items[$delta]
            );
            continue 2;
          case 'manifesturl':
            $manifests['manifesturl'] = $this->processManifestforURL(
              $jsondata
            );
            continue 2;
          case 'manifestnodelist':
            $manifests['manifestnodelist'] = $this->processManifestforNodeList(
              $jsondata
            );
            continue 2;
        }
      }

      // Check which one is our main source and if it really exists
      if (isset($manifests[$main_mediasource])
        && !empty($manifests[$main_mediasource])
      ) {
        // Take only the first since we could have more
        $main_manifesturl = array_shift($manifests[$main_mediasource]);
        $all_manifesturl = array_reduce($manifests, 'array_merge', []);
      }
      else {
        // reduce flattens and applies a merge. Basically we get a simple list.
        $all_manifesturl = array_reduce($manifests, 'array_merge', []);
        $main_manifesturl = array_shift($all_manifesturl);
      }

      // Only process is we got at least one manifest
      if (!empty($main_manifesturl)) {
        // get the URL to our Metadata Expose Endpoint, we will get a string here.
        $elements[$delta]['media']['#attributes']['data-iiif-infojson'] = '';
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield'][$viewer_drupal_settings_key][$htmlid]['nodeuuid']
          = $nodeuuid;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield'][$viewer_drupal_settings_key][$htmlid]['manifesturl']
          = $main_manifesturl;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield'][$viewer_drupal_settings_key][$htmlid]['manifestother']
          = is_array($all_manifesturl) ? $all_manifesturl : [];
      }
  }

  /**
   *  Generates URL string for a Twig generated manifest for the current Node.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF Manifest for this node.
   *    We are using an array even if we only return one
   *    to match other processManifest Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processManifestforMetadataExposeEntity(
    FieldItemInterface $item
  ) {
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $manifests = [];

    if ($this->getSetting('metadataexposeentity_source'
    )) {
      /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity */
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {
        $url = $entity->getUrlForItemFromNodeUUID($nodeuuid, TRUE);
        $manifests[] = $url;
      }
    }
    return $manifests;
  }

  /**
   *  Fetches Manifest URLs from a JSON Key.
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF Manifest for this node.
   *    We are using an array even if we only return one
   *    to match other processManifest Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processManifestforURL(
    array $jsondata
  ) {
    $manifests = [];
    if ($this->getSetting('manifesturl_json_key_source'
    )) {
      $jsonkey = $this->getSetting('manifesturl_json_key_source');

      if (isset($jsondata[$jsonkey])) {
        if (is_array($jsondata[$jsonkey])) {
          foreach ($jsondata[$jsonkey] as $url) {
            if (is_string($url) && UrlHelper::isValid($url, TRUE)) {
              $manifests[] = $url;
            }
          }
        }
        else {
          if (is_string($jsondata[$jsonkey]) && UrlHelper::isValid(
              $jsondata[$jsonkey],
              TRUE
            )) {
            $manifests[] = $jsondata[$jsonkey];
          }
        }
      }
    }
    return $manifests;
  }

  /**
   * Generates Manifest URLs from a JSON Key containing a list of nodes.
   *
   * This function reuses 'metadataexposeentity_json_key_source'
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF Manifest for this node.
   *    We are using an array even if we only return one
   *    to match other processManifest Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processManifestforNodeList(
    array $jsondata
  ) {
    $manifests = [];
    $cleannodelist = [];
    if ($this->getSetting('manifestnodelist_json_key_source') && $this->getSetting('metadataexposeentity_source')) {
      $jsonkey = $this->getSetting('manifestnodelist_json_key_source');
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {
        $access_manager = \Drupal::service('access_manager');
        if (isset($jsondata[$jsonkey])) {
          if (is_array($jsondata[$jsonkey])) {
            $cleannodelist = [];
            foreach ($jsondata[$jsonkey] as $nodeid) {
              if (is_integer($nodeid)) {
                $cleannodelist[] = $nodeid;
              }
            }
          }
          else {
            if (is_integer($jsondata[$jsonkey])) {
              $cleannodelist[] = $jsondata[$jsonkey];
            }
          }

          foreach ($this->entityTypeManager->getStorage('node')->loadMultiple(
            $cleannodelist
          ) as $node) {
            $has_access = $access_manager->checkNamedRoute(
              'format_strawberryfield.metadatadisplay_caster',
              [
                'node' => $node->uuid(),
                'metadataexposeconfig_entity' => $entity->id(),
                'format' => 'manifest.jsonld'
              ],
              $this->currentUser
            );
            if ($has_access) {
              $manifests[] = $entity->getUrlForItemFromNodeUUID(
                $node->uuid(),
                TRUE
              );
            }
          }
        }
      }
    }
    return $manifests;
  }
}
