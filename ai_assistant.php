<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'ai_assistant.civix.php';
// phpcs:enable

use CRM_AiAssistant_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function ai_assistant_civicrm_config(\CRM_Core_Config $config): void {
  _ai_assistant_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function ai_assistant_civicrm_install(): void {
  _ai_assistant_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function ai_assistant_civicrm_enable(): void {
  _ai_assistant_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_container().
 *
 * Registers the shared LlmService so any code can call Civi::service('ai.llm').
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 */
function ai_assistant_civicrm_container(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void {
  $container->setDefinition('ai.llm', new \Symfony\Component\DependencyInjection\Definition(
    \Civi\AiAssistant\LlmService::class
  ))->setPublic(TRUE);
}

/**
 * Implements hook_civicrm_permission().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_permission/
 */
function ai_assistant_civicrm_permission(&$permissions): void {
  $permissions['use ai assistant'] = [
    'label' => E::ts('AI Assistant: use'),
    'description' => E::ts('Use AI Assistant features such as natural-language search'),
  ];
  $permissions['administer ai assistant'] = [
    'label' => E::ts('AI Assistant: administer'),
    'description' => E::ts('Configure the AI provider, model and privacy settings'),
  ];
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds the end-user "AI Search" link (under Search) and the admin settings link.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu/
 */
function ai_assistant_civicrm_navigationMenu(&$menu): void {
  _ai_assistant_civix_insert_navigation_menu($menu, 'Search', [
    'label' => E::ts('AI Search'),
    'name' => 'ai_search',
    'url' => 'civicrm/ai-search',
    'permission' => 'use ai assistant',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _ai_assistant_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', [
    'label' => E::ts('AI Assistant Settings'),
    'name' => 'ai_assistant_settings',
    'url' => 'civicrm/admin/settings/ai-assistant',
    'permission' => 'administer ai assistant',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _ai_assistant_civix_navigationMenu($menu);
}
