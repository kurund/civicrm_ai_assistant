<?php

use CRM_AiAssistant_ExtensionUtil as E;

/**
 * AI Assistant settings page; shows every setting tagged with
 * 'settings_pages' => ['ai_assistant' => ...].
 */
class CRM_AiAssistant_Form_Settings extends CRM_Admin_Form_Generic {

  /**
   * The settings_pages key this form shows.
   *
   * @return string
   */
  public function getSettingPageFilter() {
    return 'ai_assistant';
  }

  /**
   * Page sections.
   */
  public function preProcess(): void {
    parent::preProcess();
    $this->sections = [
      'provider' => [
        'title' => E::ts('Provider'),
        'description' => E::ts('Default is OpenRouter (OpenAI-compatible, free tier). Point the base URL at OpenAI, Azure, or a local Ollama/vLLM server to change provider - no code change.'),
        'icon' => 'fa-plug',
        'weight' => 0,
      ],
      'privacy' => [
        'title' => E::ts('Privacy & PII'),
        'description' => E::ts('The natural-language search sends only schema + your prompt - never records. A cloud provider (including the free tier) still means data leaves your infrastructure; point the base URL at a local model for guaranteed isolation.'),
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
