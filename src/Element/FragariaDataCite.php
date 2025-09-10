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
    return $element;
  }

}
