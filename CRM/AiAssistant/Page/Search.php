<?php

use CRM_AiAssistant_ExtensionUtil as E;

/**
 * End-user "AI Search" page (route: civicrm/ai-search).
 *
 * A thin server-rendered shell; all the work happens client-side by calling the
 * Ai.searchKit APIv4 action via CRM.api4(). Deliberately not an AngularJS SPA —
 * this keeps the moving parts minimal and reliable.
 */
class CRM_AiAssistant_Page_Search extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('AI Search'));

    Civi::resources()->addScriptFile('ai_assistant', 'js/ai-search.js');
    Civi::resources()->addStyleFile('ai_assistant', 'css/ai-search.css');

    // No entity picker: Ai.searchKit auto-detects the entity from the prompt.
    parent::run();
  }

}
