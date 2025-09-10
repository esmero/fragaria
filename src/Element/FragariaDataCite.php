<?php

namespace Drupal\fragaria\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Select;

/**
 * Provides a webform element for a entity select menu.
 *
 * @FormElement("webform_fragaria_datacite_doi")
 */
class FragariaDataCite extends Select {



  /**
   * {@inheritdoc}
   */
  public static function processSelect(&$element, FormStateInterface $form_state, &$complete_form) {
    //static::setOptions($element);
    $element = parent::processSelect($element, $form_state, $complete_form);

    // Must convert this element['#type'] to a 'select' to prevent
    // "Illegal choice %choice in %name element" validation error.
    // @see \Drupal\Core\Form\FormValidator::performRequiredValidation
    $element['#type'] = 'select';
    $doi_info = NULL;
    if ( $element['#doi_id'] && is_string($element['#doi_id'])) {
      $api = $element['#doi_api'] ?? '';
      $doi_info = "Current DOI ({$api}): ". $element['#doi_id'];
      if ($element['#doi_status'] && is_string($element['#doi_status'])) {
        $doi_info = $doi_info. "(". $element['#doi_status'] . ")";
      }
    }
    if ($doi_info) {
      $doi_info = "<b>". $doi_info . "</b>";
      if ($element['#description']['#markup'] ?? NULL) {
        // Build a simplistic DOI info if any
        $element['#description']['#markup'] = $element['#description']['#markup'] . "</br>" . $doi_info;
      }
      else {
        $element['#description'] = t($doi_info);
      }
    }
    return $element;
  }

}
