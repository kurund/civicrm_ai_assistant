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
      $actions = $("#ai-actions"),
      $truncated = $("#ai-truncated"),
      $print = $("#ai-print"),
      $printAll = $("#ai-print-all"),
      $csv = $("#ai-csv"),
      $resultsAll = $("#ai-results-all"),
      $json = $("#ai-json");

    function emptyState() {
      return {
        entity: null,
        apiParams: null,
        display: null,
        messages: [],
        columns: [],
        rows: [],
        truncated: false,
      };
    }
    var state = emptyState();

    function reset() {
      state = emptyState();
      $results.empty();
      $resultsAll.empty();
      $summary.empty();
      $refineWrap.hide();
      $actions.hide();
      $json.hide();
    }

    function setBusy(busy, msg) {
      $status.text(msg || "");
      $run.prop("disabled", busy);
      $refineRun.prop("disabled", busy);
      $printAll.prop("disabled", busy);
      $csv.prop("disabled", busy);
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

    function tableHtml(cols, rows) {
      var html = '<table class="ai-table"><thead><tr>';
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
      return html;
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
      var cols =
        disp.columns && disp.columns.length ? disp.columns : guessColumns(rows);
      state.columns = cols;
      state.rows = rows;
      state.truncated = !!r.preview_truncated;

      if (disp.type === "single") {
        var first = rows[0] || {};
        var keys = Object.keys(first);
        var val = keys.length ? first[keys[0]] : ts("(no result)");
        $results.html(
          '<div class="ai-single">' + escapeHtml(fmt(val)) + "</div>",
        );
      } else {
        $results.html(tableHtml(cols, rows));
      }
      $resultsAll.empty();
      $truncated.text(
        state.truncated
          ? ts("Showing the first %1 rows.", { 1: rows.length })
          : "",
      );
      $printAll.toggle(state.truncated);
      $actions.show();
      $refineWrap.show();
      $save.show();
    }

    // APIv4 over AJAX always applies ACLs.
    function fetchAll() {
      if (!state.truncated) {
        return $.Deferred().resolve(state.rows).promise();
      }
      setBusy(true, ts("Loading all rows…"));
      return CRM.api4(state.entity, "get", state.apiParams).then(
        function (rows) {
          setBusy(false);
          return rows;
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

    function csvCell(v) {
      var s = fmt(v);
      // Neutralise spreadsheet formula injection.
      if (typeof v === "string" && /^[=+\-@\t\r]/.test(s) && isNaN(s)) {
        s = "'" + s;
      }
      return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }

    function downloadCsv(rows) {
      var lines = [
        state.columns
          .map(function (c) {
            return csvCell(c.label || c.key);
          })
          .join(","),
      ];
      rows.forEach(function (row) {
        lines.push(
          state.columns
            .map(function (c) {
              return csvCell(row[c.key]);
            })
            .join(","),
        );
      });
      // BOM so Excel detects UTF-8.
      var blob = new Blob(["\ufeff" + lines.join("\r\n")], {
        type: "text/csv;charset=utf-8",
      });
      var url = URL.createObjectURL(blob);
      var a = document.createElement("a");
      a.href = url;
      a.download =
        "ai-search-" + new Date().toISOString().slice(0, 10) + ".csv";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
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

    $run.on("click", function () {
      reset();
      ask($.trim($prompt.val()));
    });
    $refineRun.on("click", function () {
      ask($.trim($refine.val()));
    });

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

    $print.on("click", function () {
      window.print();
    });
    $printAll.on("click", function () {
      fetchAll().then(function (rows) {
        if (!rows) {
          return;
        }
        $resultsAll.html(tableHtml(state.columns, rows));
        $("body").addClass("ai-print-all");
        window.print();
      });
    });
    $(window).on("afterprint", function () {
      $("body").removeClass("ai-print-all");
    });
    $csv.on("click", function () {
      fetchAll().then(function (rows) {
        if (rows) {
          downloadCsv(rows);
        }
      });
    });

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
