{*
  AI Search page shell. Logic lives in js/ai-search.js (calls Ai.searchKit).
*}
<div class="crm-block crm-content-block" id="ai-search-app">

  <div class="help">
    {ts escape='html'}Describe what you want in plain language — e.g. "lapsed donors in the United Kingdom who gave over £100 last year". The assistant builds a query, picks how to show it, and previews the result. Nothing is saved unless you choose to.{/ts}
  </div>

  <div class="ai-search-bar">
    <label for="ai-entity">{ts}Search{/ts}</label>
    <select id="ai-entity" class="crm-form-select">
      {foreach from=$entities item=ent}
        <option value="{$ent}">{$ent}</option>
      {/foreach}
    </select>
    <textarea id="ai-prompt" class="crm-form-textarea" rows="2"
      placeholder="{ts escape='html'}Ask in plain language…{/ts}"></textarea>
    <button id="ai-run" class="crm-button">{ts}Search{/ts}</button>
    <span id="ai-status" class="ai-status"></span>
  </div>

  <div id="ai-summary" class="ai-summary"></div>

  <div id="ai-results" class="ai-results"></div>

  <div id="ai-refine-wrap" class="ai-refine-wrap" style="display:none;">
    <label for="ai-refine">{ts}Refine{/ts}</label>
    <textarea id="ai-refine" class="crm-form-textarea" rows="1"
      placeholder="{ts escape='html'}e.g. only include this year, add their email, sort by amount…{/ts}"></textarea>
    <button id="ai-refine-run" class="crm-button">{ts}Refine{/ts}</button>
    <button id="ai-save" class="crm-button" style="display:none;">{ts}Save as SearchKit{/ts}</button>
  </div>

  <details id="ai-json" class="ai-json" style="display:none;">
    <summary>{ts}Generated query (api_params){/ts}</summary>
    <pre></pre>
  </details>

</div>
