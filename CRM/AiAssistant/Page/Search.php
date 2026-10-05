<?php

use CRM_AiAssistant_ExtensionUtil as E;

/**
 * AI Search page shell; js/ai-search.js does the work through Ai.searchKit.
 */
class CRM_AiAssistant_Page_Search extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('AI Search'));

    Civi::resources()->addScriptFile('ai_assistant', 'js/ai-search.js');
    Civi::resources()->addStyleFile('ai_assistant', 'css/ai-search.css');

    parent::run();
  }

}
