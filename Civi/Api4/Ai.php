<?php

namespace Civi\Api4;

/**
 * AI Assistant services.
 *
 * @searchable none
 * @package Civi\Api4
 */
class Ai extends Generic\AbstractEntity {

  /**
   * Generic LLM completion.
   *
   * @param bool $checkPermissions
   * @return Action\Ai\Prompt
   */
  public static function prompt($checkPermissions = TRUE) {
    return (new Action\Ai\Prompt(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Natural-language -> SearchKit query + display spec, with transient preview.
   *
   * @param bool $checkPermissions
   * @return Action\Ai\SearchKit
   */
  public static function searchKit($checkPermissions = TRUE) {
    return (new Action\Ai\SearchKit(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [
        ['name' => 'response'],
        ['name' => 'api_params'],
        ['name' => 'display'],
        ['name' => 'preview'],
        ['name' => 'preview_truncated'],
        ['name' => 'summary'],
        ['name' => 'changed'],
      ];
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * Permissions per action.
   *
   * @return array
   */
  public static function permissions() {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['use ai assistant'],
    ];
  }

}
