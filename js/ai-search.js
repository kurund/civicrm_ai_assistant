// AI Search — calls the Ai.searchKit APIv4 action and renders the result.
// Generate -> refine -> preview loop. Nothing is persisted unless "Save" is used.
(function ($, ts) {
  "use strict";

  $(function () {
    var $prompt = $("#ai-prompt"),
      $run = $("#ai-run"),
      $status = $("#ai-status"),
      $summary = $("#ai-summary"),
      $results = $("#ai-results"),
      $refineWrap = $("#ai-refine-wrap"),
      $refine = $("#ai-refine"),
      $refineRun = $("#ai-refine-run"),
      $save = $("#ai-save"),
      $json = $("#ai-json");

    // Transient draft state — never written to the database here. The entity is
    // auto-detected server-side from the first prompt (no drop-down), then locked
    // for refinements of the same draft.
    var state = {
      entity: null,
      apiParams: null,
      display: null,
      messages: [],
    };

    function reset() {
      state = { entity: null, apiParams: null, display: null, messages: [] };
      $results.empty();
      $summary.empty();
      $refineWrap.hide();
      $json.hide();
    }

    function setBusy(busy, msg) {
      $status.text(msg || "");
      $run.prop("disabled", busy);
      $refineRun.prop("disabled", busy);
    }

    function escapeHtml(s) {
      return $("<div>")
        .text(s == null ? "" : s)
        .html();
    }

    function fmt(v) {
      if (v == null) {
        return "";
      }
      if (typeof v === "object") {
        return JSON.stringify(v);
      }
      return String(v);
    }

    function guessColumns(rows) {
      if (!rows.length) {
        return [];
      }
      return Object.keys(rows[0]).map(function (k) {
        return { key: k, label: k };
      });
    }

    function render(r) {
      $summary.empty();
      if (r.api_entity) {
        $summary.append(
          $('<span class="ai-entity-badge">').text(r.api_entity),
        );
      }
      $summary.append(document.createTextNode(r.summary || ""));
      if (r.warning) {
        CRM.alert(r.warning, ts("Preview"), "warning");
      }
      $json
        .show()
        .find("pre")
        .text(JSON.stringify(r.api_params || {}, null, 2));

      var disp = r.display || { type: "table" };
      var rows = r.preview || [];
      var html;

      if (disp.type === "single") {
        var first = rows[0] || {};
        var keys = Object.keys(first);
        var val = keys.length ? first[keys[0]] : ts("(no result)");
        html = '<div class="ai-single">' + escapeHtml(fmt(val)) + "</div>";
      } else {
        var cols =
          disp.columns && disp.columns.length
            ? disp.columns
            : guessColumns(rows);
        html = '<table class="ai-table"><thead><tr>';
        cols.forEach(function (c) {
          html += "<th>" + escapeHtml(c.label || c.key) + "</th>";
        });
        html += "</tr></thead><tbody>";
        rows.forEach(function (row) {
          html += "<tr>";
          cols.forEach(function (c) {
            html += "<td>" + escapeHtml(fmt(row[c.key])) + "</td>";
          });
          html += "</tr>";
        });
        html += "</tbody></table>";
        if (!rows.length) {
          html += '<p class="ai-empty">' + ts("No matching rows.") + "</p>";
        }
      }
      $results.html(html);
      $refineWrap.show();
      $save.show();
    }

    function ask(promptText) {
      if (!promptText) {
        return;
      }
      setBusy(true, ts("Thinking…"));
      CRM.api4("Ai", "searchKit", {
        prompt: promptText,
        entity: state.entity,
        apiParams: state.apiParams,
        display: state.display,
        messages: state.messages,
      }).then(
        function (rows) {
          var r = rows[0] || {};
          // Lock the auto-detected entity so refinements stay on the same draft.
          state.entity = r.api_entity || state.entity;
          state.apiParams = r.api_params || null;
          state.display = r.display || null;
          state.messages.push({ role: "user", content: promptText });
          render(r);
          setBusy(false);
          $refine.val("").focus();
        },
        function (err) {
          setBusy(false);
          CRM.alert(
            err && err.error_message ? err.error_message : ts("Request failed"),
            ts("AI Search"),
            "error",
          );
        },
      );
    }

    // A top-level search always starts a fresh draft (re-detecting the entity);
    // "Refine" continues the current draft.
    $run.on("click", function () {
      reset();
      ask($.trim($prompt.val()));
    });
    $refineRun.on("click", function () {
      ask($.trim($refine.val()));
    });

    // Enter submits the main prompt (Shift+Enter for newline).
    $prompt.on("keydown", function (e) {
      if (e.which === 13 && !e.shiftKey) {
        e.preventDefault();
        $run.click();
      }
    });
    $refine.on("keydown", function (e) {
      if (e.which === 13 && !e.shiftKey) {
        e.preventDefault();
        $refineRun.click();
      }
    });

    // Save the current transient draft as a real SearchKit SavedSearch.
    $save.on("click", function () {
      if (!state.apiParams) {
        return;
      }
      var label = $.trim($prompt.val()).slice(0, 80) || ts("AI search");
      setBusy(true, ts("Saving…"));
      CRM.api4("SavedSearch", "create", {
        values: {
          label: label,
          api_entity: state.entity,
          api_params: state.apiParams,
        },
      }).then(
        function (saved) {
          setBusy(false);
          var id = saved[0] && saved[0].id ? saved[0].id : null;
          CRM.alert(
            ts("Saved. Open Search Kit to add a display or schedule it.") +
              (id ? " (#" + id + ")" : ""),
            ts("Saved search"),
            "success",
          );
        },
        function (err) {
          setBusy(false);
          CRM.alert(
            err && err.error_message ? err.error_message : ts("Save failed"),
            ts("AI Search"),
            "error",
          );
        },
      );
    });
  });
})(CRM.$, CRM.ts("ai_assistant"));
