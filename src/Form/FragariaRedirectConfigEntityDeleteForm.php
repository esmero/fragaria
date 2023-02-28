<?php
namespace Drupal\fragaria\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Form controller for the Fragaria Redirect Config entity delete form.
 *
 * @ingroup fragaria
 */
class FragariaRedirectConfigEntityDeleteForm extends EntityConfirmFormBase {

  public function getQuestion() {
    return $this->t('Are you sure you want to delete %name?', ['%name' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.fragariaredirect_entity.collection');
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->entity->delete();

    $this->messenger()->addMessage(
      $this->t('Fragaria Redirect Config Entity @label deleted.',
        [
          '@label' => $this->entity->getLabel(),
        ]
      )
    );

    $form_state->setRedirectUrl($this->getCancelUrl());
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    return parent::buildForm($form, $form_state);
  }

}

