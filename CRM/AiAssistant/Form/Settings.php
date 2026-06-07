<?php

use CRM_AiAssistant_ExtensionUtil as E;

/**
 * AI Assistant settings page.
 *
 * Metadata-driven: every setting tagged with
 *   'settings_pages' => ['ai_assistant' => [...]]
 * in settings/ai_assistant.setting.php renders here automatically. No hand-built
 * HTML — CRM_Admin_Form_Generic builds the fields, defaults and save logic.
 *
 * Route: civicrm/admin/settings/ai-assistant (see xml/Menu/ai_assistant.xml).
 */
class CRM_AiAssistant_Form_Settings extends CRM_Admin_Form_Generic {

  /**
   * Pin the page filter to our own key so it doesn't depend on URL parsing.
   *
   * @return string
   */
  public function getSettingPageFilter() {
    return 'ai_assistant';
  }

  /**
   * Declare the sections shown on the page.
   */
  public function preProcess(): void {
    parent::preProcess();
    $this->sections = [
      'provider' => [
        'title' => E::ts('Provider'),
        'description' => E::ts('Default is OpenRouter (OpenAI-compatible, free tier). Point the base URL at OpenAI, Azure, or a local Ollama/vLLM server to change provider — no code change.'),
        'icon' => 'fa-plug',
        'weight' => 0,
      ],
      'privacy' => [
        'title' => E::ts('Privacy & PII'),
        'description' => E::ts('The natural-language search sends only schema + your prompt — never records. A cloud provider (including the free tier) still means data leaves your infrastructure; point the base URL at a local model for guaranteed isolation.'),
        'icon' => 'fa-user-shield',
        'weight' => 10,
      ],
      'limits' => [
        'title' => E::ts('Limits'),
        'description' => E::ts('Caps that bound cost and how much data can ever be sent or previewed.'),
        'icon' => 'fa-gauge',
        'weight' => 20,
      ],
    ];
  }

}
