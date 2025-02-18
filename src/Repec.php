<?php

namespace Drupal\repec;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\Entity\File;
use Drupal\repec\Series\BaseInterface;

/**
 * Class Repec.
 */
class Repec implements RepecInterface {

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\File\FileSystemInterface definition.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * System wide settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $settings;

  /**
   * Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Template factory.
   *
   * @var \Drupal\repec\TemplateFactory
   */
  protected $templateFactory;

  /**
   * Template class.
   *
   * @var \Drupal\repec\Series\Base|null
   */
  protected $templateClass = NULL;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Repec constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   File system service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   * @param \Drupal\repec\TemplateFactory $template_factory
   *   Template factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module handler.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, ConfigFactoryInterface $config_factory, MessengerInterface $messenger, TemplateFactory $template_factory, ModuleHandlerInterface $module_handler) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    $this->settings = $this->configFactory->get('repec.settings');
    $this->messenger = $messenger;
    $this->templateFactory = $template_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getArchiveDirectory() {
    // @todo check config
    $basePath = $this->settings->get('base_path');
    $archiveCode = $this->settings->get('archive_code');
    $result = 'public://' . $basePath . '/' . $archiveCode . '/';
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function initializeTemplates() {
    $basePath = $this->settings->get('base_path');
    if (empty($basePath)) {
      $this->messenger->addError(t('The base path cannot be empty.'));
      return;
    }

    $archiveDirectory = $this->getArchiveDirectory();
    if (!empty($archiveDirectory) &&
      file_prepare_directory($archiveDirectory, FILE_CREATE_DIRECTORY)) {
      // Remove all files of type .rdf.
      // @todo use Drupal file system unlink
      $files = glob($this->getArchiveDirectory() . '/*.rdf');
      foreach ($files as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }

      // Site wide templates.
      $this->createArchiveTemplate();
      $this->allowDirectoryIndex();

      // @todo extend to other entity types
      foreach ($this->getEnabledEntityTypeBundles('node_type') as $nodeType) {
        // @todo create templates for existing entities, for each content type
        // $entityIds = $this->entityTypeManager
        // ->getStorage($nodeType)->loadMultiple();
      }
    }
    else {
      $this->messenger->addError(t('Directory @path could not be created.', [
        '@path' => $basePath,
      ]));
    }

  }

  /**
   * RePEc needs the directory index, override .htaccess directive.
   */
  private function allowDirectoryIndex() {
    $directory = $this->getArchiveDirectory();
    $fileName = '.htaccess';
    // @todo needs security review
    $content = <<<EOF
Options +Indexes
# Unset Drupal_Security_Do_Not_Remove_See_SA_2006_006
SetHandler None
<Files *>
  # Unset Drupal_Security_Do_Not_Remove_See_SA_2013_003
  SetHandler None
  ForceType text/plain
</Files>
EOF;

    if (!file_put_contents($directory . '/' . $fileName, $content)) {
      $this->messenger->addError(t('File @file_name could not be created', [
        '@file_name' => $fileName,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getArchiveTemplate() {
    // @todo use hook_repec_archive_mapping
    $url = $this->settings->get('provider_homepage');
    $url .= file_url_transform_relative(file_create_url('public://'));
    $url .= $this->settings->get('base_path');
    $url .= '/' . $this->settings->get('archive_code') . '/';
    return [
      [
        'attribute' => 'Template-Type',
        'value' => 'ReDIF-Archive 1.0',
      ],
      [
        'attribute' => 'Handle',
        'value' => 'RePEc:' . $this->settings->get('archive_code'),
      ],
      [
        'attribute' => 'Name',
        'value' => $this->settings->get('provider_name'),
      ],
      [
        'attribute' => 'Maintainer-Name',
        'value' => $this->settings->get('maintainer_name'),
      ],
      [
        'attribute' => 'Maintainer-Email',
        'value' => $this->settings->get('maintainer_email'),
      ],
      [
        'attribute' => 'Description',
        // @todo review 'publications'
        'value' => 'This archive collects publications from ' . $this->settings->get('provider_name'),
      ],
      [
        'attribute' => 'URL',
        'value' => $url,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSeriesTemplate(array $entity_bundle_settings, $type): array {
    // @todo use hook_repec_series_mapping
    return [
      [
        'attribute' => 'Template-Type',
        'value' => 'ReDIF-Series 1.0',
      ],
      [
        'attribute' => 'Name',
        'value' => $entity_bundle_settings['serie_name'],
      ],
      [
        'attribute' => 'Provider-Name',
        'value' => $this->settings->get('provider_name'),
      ],
      [
        'attribute' => 'Provider-Homepage',
        'value' => $this->settings->get('provider_homepage'),
      ],
      [
        'attribute' => 'Provider-Institution',
        'value' => $this->settings->get('provider_institution'),
      ],
      [
        'attribute' => 'Maintainer-Name',
        'value' => $this->settings->get('maintainer_name'),
      ],
      [
        'attribute' => 'Maintainer-Email',
        'value' => $this->settings->get('maintainer_email'),
      ],
      [
        'attribute' => 'Type',
        'value' => $type,
      ],
      [
        'attribute' => 'Handle',
        'value' => "RePEc:{$this->settings->get('archive_code')}:{$entity_bundle_settings['serie_type']}",
      ],
    ];
  }

  /**
   * Gets the value of a field based on a RePEC attribute.
   *
   * The attribute / field mapping is done via the entity type configuration.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to get the field value from.
   * @param string $attribute_key
   *   The attribute key that is mapped to the field for the entity bundle.
   * @param string $attribute_name
   *   The RDF attribute name used in the template.
   *
   * @return array
   *   The field values to be used in the RDF template.
   */
  private function getFieldValues(ContentEntityInterface $entity, $attribute_key, $attribute_name) {
    $result = [];
    $fieldValue = $this->getFieldValueFromAttribute($entity, $attribute_key);
    switch ($attribute_key) {
      // Files need to append the File-Format and is single valued
      // so it is limited to the first one.
      case 'file_url':
        $result = $this->getFileAttributes($fieldValue);
        break;

      // Authors can be multiple.
      // @todo they can also have multiple attributes
      // currently limiting to Author-Name by default. Author-Name-First,
      // Author-Name-Last, Author-Email, Author-Workplace-Name can also
      // be provided as RePEc template attributes, see
      // https://ideas.repec.org/t/papertemplate.html
      // See issue #3 https://github.com/r-daneelolivaw/repec/issues/3
      case 'author_name':
        $authors = $this->getAuthorAttributes($fieldValue);
        foreach ($authors as $author) {
          $result[] = [
            'attribute' => $attribute_name,
            'value' => $author,
          ];
        }
        break;

      // Abstract needs post-processing.
      case 'abstract':
        $value = strip_tags($this->getDefaultAttributeValue($fieldValue));
        $value = str_replace(["\r", "\n"], '', $value);
        $result[] = [
          'attribute' => $attribute_name,
          'value' => $value,
        ];
        break;

      // Keywords can be multiple
      // and are loaded from the taxonomy.
      case 'keywords':
        $result[] = [
          'attribute' => $attribute_name,
          'value' => $this->getKeywordsValue($fieldValue),
        ];
        break;

      case 'creation_date':
        $result[] = [
          'attribute' => $attribute_name,
          'value' => date('Y-m-d', $this->getDefaultAttributeValue($fieldValue)),
        ];
        break;

      default:
        // Default to single valued attribute mapping.
        $result[] = [
          'attribute' => $attribute_name,
          'value' => $this->getDefaultAttributeValue($fieldValue),
        ];
        break;
    }
    return $result;
  }

  /**
   * Get the entity field value for a RePEc attribute.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity that will be used to get its bundle configuration.
   * @param string $attribute_key
   *   The RePEc attribute that is mapped to the field.
   *
   * @return array
   *   Entity field value.
   */
  private function getFieldValueFromAttribute(ContentEntityInterface $entity, $attribute_key) {
    $result = [];
    $fieldName = $this->getEntityBundleSettings($attribute_key, $entity->getEntityTypeId(), $entity->bundle());
    if ($entity->hasField($fieldName)) {
      $result = $entity->get($fieldName)->getValue();
    }
    return $result;
  }

  /**
   * Get a single valued attribute.
   *
   * @param array $field_value
   *   Entity field value.
   *
   * @return string
   *   Attribute value.
   */
  private function getDefaultAttributeValue(array $field_value) {
    return empty($field_value[0]['value']) ? '' : $field_value[0]['value'];
  }

  /**
   * Get a list of keywords from taxonomy terms referenced by an entity.
   *
   * @param array $field_value
   *   Entity field value.
   *
   * @return string
   *   Comma separated list of keywords.
   */
  private function getKeywordsValue(array $field_value) {
    $result = '';
    try {
      // Could be replaced by field->referencedEntities but needs refactoring
      // to get field instead of fieldValue from getFieldValues().
      if (!empty($field_value[0]['target_id'])) {
        $tids = [];
        foreach ($field_value as $value) {
          $tids[] = $value['target_id'];
        }
        $terms = $this->entityTypeManager->getStorage('taxonomy_term')
          ->loadMultiple($tids);
        $termNames = [];
        /** @var \Drupal\taxonomy\Entity\Term $term */
        foreach ($terms as $term) {
          // @todo get translation
          $termNames[] = $term->getName();
        }
        $result = implode(', ', $termNames);
      }
    }
    catch (InvalidPluginDefinitionException $exception) {
      $this->messenger->addError($exception->getMessage());
    }
    return $result;
  }

  /**
   * Get RePEc attribute/value pairs for an entity file field value.
   *
   * @param array $field_value
   *   Entity field value.
   *
   * @return array
   *   List of attributes/values for a RePEc file.
   */
  private function getFileAttributes(array $field_value) {
    $result = [];

    foreach ($field_value as $item) {
      $file = File::load($item['target_id']);
      $uri = $file->getFileUri();
      $url = str_replace(' ', '%20', file_create_url($uri));
      $result[] = [
        'attribute' => 'File-URL',
        'value' => $url,
      ];
      $result[] = [
        'attribute' => 'File-Format',
        'value' => ucfirst($file->getMimeType()),
      ];
    }

    return $result;
  }

  /**
   * Get a RePEc attribute/value pairs for an entity author field value.
   *
   * @param array $field_value
   *   Entity field value.
   *
   * @return array
   *   List of attributes/values for RePEc author(s) cluster.
   */
  private function getAuthorAttributes(array $field_value) {
    $result = [];
    // @see issue #3 https://github.com/r-daneelolivaw/repec/issues/3
    // @todo this field can be from several types (user reference, text, ...)
    // The author field set from the bundle configuration could be from several
    // types (user reference, text, ...).
    // The current implementation assumes that user reference type
    // is the default.
    // This needs at least a field validation during the field mapping on the
    // bundle settings form, then coverage for other types.
    try {
      // Could be replaced by field->referencedEntities but needs refactoring
      // to get field instead of fieldValue from getFieldValues().
      if (!empty($field_value[0]['target_id'])) {
        $uids = [];
        foreach ($field_value as $value) {
          $uids[] = $value['target_id'];
        }
        $users = $this->entityTypeManager->getStorage('user')
          ->loadMultiple($uids);
        /** @var \Drupal\user\Entity\User $user */
        foreach ($users as $user) {
          // @todo this needs to be set from the config as user names
          // can be fetched from first name / last name instead of
          // the username and can produce other attributes like
          // Author-Name-First and Author-Name-Last
          $result[] = $user->getUsername();
        }
      }
    }
    catch (InvalidPluginDefinitionException $exception) {
      $this->messenger->addError($exception->getMessage());
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getTemplateFields($templateType) {
    // @todo extend to other templates via a factory
    $result = [];
    switch ($templateType) {
      // @todo this is a port of the Drupal 7 module review paper template
      // as there are many more fields:
      // https://ideas.repec.org/t/papertemplate.html
      // @todo take into account mandatory fields as required in the entity type
      // settings form: Template-Type:, Author-Name:, Title: and Handle
      // add this as and extra field attribute, needs refactoring.
      case RepecInterface::SERIES_WORKING_PAPER:
        $result = [
          'author_name' => t('Author-Name'),
          'abstract' => t('Abstract'),
          'creation_date' => t('Creation-Date'),
          'file_url' => t('File-URL'),
          'keywords' => t('Keywords'),
        ];
        break;

      case RepecInterface::SERIES_JOURNAL_ARTICLE:
        $result = [
          'author_name' => t('Author-Name'),
          'abstract' => t('Abstract'),
          'creation_date' => t('Creation-Date'),
          'file_url' => t('File-URL'),
          'keywords' => t('Keywords'),
        ];
        break;

      case RepecInterface::SERIES_BOOK:
        $result = [
          'author_name' => t('Author-Name'),
          'provider_name' => t('Provider-Name'),
          'abstract' => t('Abstract'),
          'creation_date' => t('Creation-Date'),
          'file_url' => t('File-URL'),
          'keywords' => t('Keywords'),
        ];
        break;

      case RepecInterface::SERIES_BOOK_CHAPTER:
        $result = [
          'author_name' => t('Author-Name'),
          'abstract' => t('Abstract'),
          'creation_date' => t('Creation-Date'),
          'file_url' => t('File-URL'),
          'keywords' => t('Keywords'),
        ];
        break;

      case RepecInterface::SERIES_SOFTWARE_COMPONENT:
        $result = [
          'author_name' => t('Author-Name'),
          'abstract' => t('Abstract'),
          'creation_date' => t('Creation-Date'),
          'file_url' => t('File-URL'),
          'keywords' => t('Keywords'),
        ];
        break;
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTemplate(ContentEntityInterface $entity) {
    /** @var string $series_type */
    $series_type = $this->getEntityBundleSettings('serie_type', $entity->getEntityTypeId(), $entity->bundle());
    /** @var \Drupal\repec\Series\Base $template_class */
    $template_class = $this->getTemplateClass($series_type, $entity);
    $template = $template_class->getDefault();

    $templateFields = $this->getTemplateFields($series_type);
    foreach ($templateFields as $attributeKey => $attributeName) {
      foreach ($this->getFieldValues($entity, $attributeKey, $attributeName->render()) as $fieldValue) {
        $template[] = $fieldValue;
      }
    }

    $this->moduleHandler->alter('repec_template', $template, $entity);
    $this->moduleHandler->alter("repec_template_{$series_type}", $template, $entity);

    return $template;
  }

  /**
   * Creates the archive template.
   */
  private function createArchiveTemplate() {
    $template = $this->getArchiveTemplate();
    $this->createTemplate($template, RepecInterface::TEMPLATE_ARCHIVE);
  }

  /**
   * {@inheritdoc}
   */
  public function createSeriesTemplate(ContentEntityInterface $entity) {
    /** @var array $entity_bundle_settings */
    $entity_bundle_settings = $this->getEntityBundleSettings('all', $entity->getEntityTypeId(), $entity->bundle());

    if ($this->shouldCreateSeriesTemplate($entity_bundle_settings)) {
      /** @var \Drupal\repec\Series\BaseInterface $template_class */
      $template_class = $this->getTemplateClass($this->getEntityBundleSettings('serie_type', $entity->getEntityTypeId(), $entity->bundle()), $entity);
      $template = $this->getSeriesTemplate($entity_bundle_settings, $template_class->getSeriesType());
      $this->appendTemplate($template, RepecInterface::TEMPLATE_SERIES);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function appendTemplate(array $template, $templateType): void {
    $directory = $this->getArchiveDirectory();
    $fileName = $this->settings->get('archive_code') . $templateType . '.rdf';
    $content = '';
    foreach ($template as $item) {
      $content .= $item['attribute'] . ': ' . $item['value'] . "\n";
    }

    if (!\file_put_contents($directory . '/' . $fileName, $content . PHP_EOL, FILE_APPEND)) {
      $this->messenger->addError(t('File @file_name could not be created', [
        '@file_name' => $fileName,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createTemplate(array $template, $templateType) {
    $directory = $this->getArchiveDirectory();
    $fileName = $this->settings->get('archive_code') . $templateType . '.rdf';
    $content = '';
    foreach ($template as $item) {
      $content .= $item['attribute'] . ': ' . $item['value'] . "\n";
    }

    if (!file_put_contents($directory . '/' . $fileName, $content)) {
      $this->messenger->addError(t('File @file_name could not be created', [
        '@file_name' => $fileName,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createEntityTemplate(ContentEntityInterface $entity) {
    /** @var array $template */
    $template = $this->getEntityTemplate($entity);
    $this->createSeriesTemplate($entity);

    /** @var \Drupal\repec\Series\Base $template_class */
    $template_class = $this->getTemplateClass($this->getEntityBundleSettings('serie_type', $entity->getEntityTypeId(), $entity->bundle()), $entity);

    try {
      $template_class->create($template);
    }
    catch (\Exception $e) {
      $this->messenger->addError($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateEntityTemplate(ContentEntityInterface $entity, $templateType) {
    // Barely re-create the entity template.
    $this->createEntityTemplate($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteEntityTemplate(ContentEntityInterface $entity) {
    $serieDirectoryConfig = $this->getEntityBundleSettings('serie_directory', $entity->getEntityTypeId(), $entity->bundle());
    $directory = $this->getArchiveDirectory() . $serieDirectoryConfig . '/';
    if (!empty($directory)) {
      $fileName = $serieDirectoryConfig . '_' . $entity->getEntityTypeId() . '_' . $entity->id() . '.rdf';
      $filePath = $directory . '/' . $fileName;
      file_unmanaged_delete($filePath);
    }
    else {
      $this->messenger->addError(t('The directory @path is empty.', [
        '@path' => $directory,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function availableSeries() {
    return [
      RepecInterface::SERIES_WORKING_PAPER => t('Paper series'),
      RepecInterface::SERIES_JOURNAL_ARTICLE => t('Journal article series'),
      RepecInterface::SERIES_BOOK => t('Book series'),
      RepecInterface::SERIES_BOOK_CHAPTER => t('Book chapter series'),
      RepecInterface::SERIES_SOFTWARE_COMPONENT => t('Software component series'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isBundleEnabled(ContentEntityInterface $entity) {
    return $this->getEntityBundleSettings('enabled', $entity->getEntityTypeId(), $entity->bundle());
  }

  /**
   * {@inheritdoc}
   */
  public function isEntityShareable(ContentEntityInterface $entity) {
    // If a restriction is configured for this bundle,
    // get the field that is used for the restriction,
    // then get the entity field value.
    $hasRestriction = $this->getEntityBundleSettings('restriction_by_field', $entity->getEntityTypeId(), $entity->bundle()) === 1;

    if (!$hasRestriction) {
      return TRUE;
    }

    $restrictionField = $this->getEntityBundleSettings('restriction_field', $entity->getEntityTypeId(), $entity->bundle());
    return $entity->get($restrictionField)->getValue()[0]['value'] === 1;
  }

  /**
   * Returns a list of enabled entity types.
   *
   * Example: if entity_type_id is node_type, returns the enabled content types.
   *
   * @param string $entity_type_id
   *   The entity type (e.g. node_type);.
   *
   * @return array
   *   List of enabled entity types.
   */
  private function getEnabledEntityTypeBundles($entity_type_id) {
    $result = [];
    try {
      $entityTypes = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple();
      foreach ($entityTypes as $entityType) {
        // @todo check enabled
        $result[] = $entityType->id();
      }
    }
    catch (InvalidPluginDefinitionException $exception) {
      $this->messenger->addError($exception->getMessage());
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityBundleSettings($setting, $entity_type_id, $bundle) {
    $config = $this->configFactory->getEditable('repec.settings');
    $settings = unserialize($config->get('repec_bundle.' . $entity_type_id . '.' . $bundle));
    if (empty($settings)) {
      $settings = [];
    }
    $settings += $this->getEntityBundleSettingDefaults();

    if ($setting == 'all') {
      return $settings;
    }
    return isset($settings[$setting]) ? $settings[$setting] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityBundleSettings(array $settings, $entity_type_id, $bundle) {
    $config = $this->configFactory->getEditable('repec.settings');
    // Do not store default values.
    foreach ($this->getEntityBundleSettingDefaults() as $setting => $default_value) {
      if (isset($settings[$setting]) && $settings[$setting] == $default_value) {
        unset($settings[$setting]);
      }
    }
    $config->set('repec_bundle.' . $entity_type_id . '.' . $bundle, serialize($settings));
    $config->save();
  }

  /**
   * {@inheritdoc}
   */
  public function availableEntityBundleSettings() {
    return [
      'enabled',
      'serie_type',
      'serie_name',
      'is_different_serie_directory',
      'serie_directory',
      'restriction_by_field',
      'restriction_field',
      'author_name',
      'abstract',
      'creation_date',
      'file_url',
      'keywords',
      'provider_name',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityBundleSettingDefaults() {
    $defaults = [];
    $defaults['enabled'] = FALSE;
    $defaults['serie_type'] = '';
    $defaults['serie_name'] = '';
    $defaults['is_different_serie_directory'] = TRUE;
    $defaults['serie_directory'] = '';
    $defaults['restriction_by_field'] = '';
    $defaults['restriction_field'] = '';
    $defaults['author_name'] = '';
    $defaults['abstract'] = '';
    $defaults['creation_date'] = '';
    $defaults['file_url'] = '';
    $defaults['keywords'] = '';
    $defaults['provider_name'] = '';
    return $defaults;
  }

  /**
   * Returns a template class.
   *
   * Creates one if not already created.
   *
   * @param string $series_type
   *   Series type of the template class.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity for the template class.
   *
   * @return \Drupal\repec\Series\BaseInterface
   *   The template class.
   */
  private function getTemplateClass($series_type, ContentEntityInterface $entity) : BaseInterface {
    if (!$this->templateClass) {
      $this->templateClass = $this->templateFactory->create($series_type, $entity);
    }

    return $this->templateClass;
  }

  /**
   * {@inheritdoc}
   */
  public function shouldCreateSeriesTemplate(array $settings): bool {
    $directory = $this->getArchiveDirectory();
    $file_name = "{$this->settings->get('archive_code')}seri.rdf";
    /** @var string $real_path */
    $real_path = $this->fileSystem->realpath("$directory/$file_name");
    $is_file_exists = \file_exists($real_path);

    if (!$is_file_exists) {
      return TRUE;
    }

    $content = \file_get_contents($real_path);

    if (FALSE === $content) {
      return FALSE;
    }

    // Series template creation is decided by checking whether the series name
    // is already present in the template.
    return !(\strpos($content, "Name: {$settings['serie_name']}") !== FALSE);
  }

}
